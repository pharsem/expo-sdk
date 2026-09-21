<?php

declare(strict_types=1);

namespace Expo\Push\Tests\Unit;

use Expo\Push\Exception\InvalidStorageException;
use Expo\Push\Http\TransportFailureKind;
use Expo\Push\PushMessage;
use Expo\Push\PushTicket;
use Expo\Push\Result\Acceptance;
use Expo\Push\Result\FailureCategory;
use Expo\Push\Retry\ConservativeSendPolicy;
use Expo\Push\Retry\DeliveryRetryPolicy;
use Expo\Push\Retry\RetrySettings;
use Expo\Push\Storage\StorageEnvelope;
use Expo\Push\Tests\Support\FakeConcurrentHttpClient;
use Expo\Push\Tests\Support\FakeHttpClient;
use Expo\Push\Tests\Support\RecordingLimiter;
use Expo\Push\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The findings that the review marked "previously missed", one test for each.
 */
final class MissedFindingsTest extends TestCase
{
    /**
     * A concurrent transport can refuse a request before it starts. The SDK
     * returns a result for that, the same as the sequential path does.
     */
    public function testAStartFailureOfAConcurrentTransportBecomesAResult(): void
    {
        $http = new FakeConcurrentHttpClient(refuseStart: TransportFailureKind::InvalidConfiguration);

        // Before the fix this call raised TransportException.
        $result = $this->expo($http, concurrency: 2, sendChunkSize: 1)
            ->send(PushMessage::to([self::TOKEN_A, self::TOKEN_B]));

        self::assertCount(2, $result->requestFailures());
        self::assertSame(FailureCategory::Transport, $result->requestFailures()[0]->category);
        self::assertSame('invalid_configuration', $result->requestFailures()[0]->transportCode);
        // The transport refused before a byte left the process.
        self::assertCount(2, $result->notAccepted());
        self::assertSame(Acceptance::NotAccepted, $result->outcomes()[0]->acceptance);
    }

    public function testTheSequentialPathGivesTheSameResult(): void
    {
        $http = (new FakeHttpClient())->queueFailure(TransportFailureKind::InvalidConfiguration);

        $result = $this->expo($http, concurrency: 1, sendChunkSize: 1)
            ->send(PushMessage::to([self::TOKEN_A, self::TOKEN_B]));

        self::assertCount(2, $result->requestFailures());
        self::assertSame(FailureCategory::Transport, $result->requestFailures()[0]->category);
        self::assertSame(FailureCategory::Skipped, $result->requestFailures()[1]->category);
        self::assertSame(Acceptance::NotAccepted, $result->outcomes()[0]->acceptance);
        self::assertSame(Acceptance::NotAttempted, $result->outcomes()[1]->acceptance);
    }

    /**
     * A deferral ends the wait. The SDK must not sleep the whole limiter delay
     * before it marks the later chunks skipped.
     */
    public function testADeferredLimiterWaitNeverSleepsTheCooldown(): void
    {
        $http = new FakeHttpClient();
        $limiter = (new RecordingLimiter())->deny(90_000);

        $result = $this->expo($http, rateLimiter: $limiter, bucket: 'p', sendChunkSize: 1)
            ->send(PushMessage::to([self::TOKEN_A, self::TOKEN_B, self::TOKEN_C]));

        self::assertSame(0, $http->requestCount());
        self::assertSame([], $this->sleeper->waits);
        self::assertCount(3, $result->notAttempted());
        self::assertSame(FailureCategory::RateLimited, $result->requestFailures()[0]->category);
        self::assertSame(FailureCategory::Skipped, $result->requestFailures()[1]->category);
    }

    public function testADeferredLimiterWaitStillDefersEveryChunkOnContinue(): void
    {
        $http = new FakeHttpClient();
        $limiter = (new RecordingLimiter())->deny(90_000)->deny(90_000);

        $result = $this->expo(
            $http,
            rateLimiter: $limiter,
            bucket: 'p',
            continueAfterFailure: true,
            sendChunkSize: 1,
        )->send(PushMessage::to([self::TOKEN_A, self::TOKEN_B]));

        self::assertSame(0, $http->requestCount());
        self::assertSame([], $this->sleeper->waits);
        self::assertCount(2, $result->requestFailures());
        self::assertSame(FailureCategory::RateLimited, $result->requestFailures()[1]->category);
    }

    /**
     * A malformed entry means that Expo answered and the SDK cannot read the
     * answer. A resend can duplicate the notification.
     */
    public function testAMalformedTicketEntryCarriesTheDuplicateRisk(): void
    {
        $http = (new FakeHttpClient())->queueRaw('{"data": ["garbage", {"status": "ok", "id": "t2"}]}');

        $result = $this->expo($http)->send(PushMessage::to([self::TOKEN_A, self::TOKEN_B]));

        self::assertSame(Acceptance::Unknown, $result->outcomes()[0]->acceptance);
        self::assertTrue($result->outcomes()[0]->duplicateRisk);
        self::assertCount(1, $result->duplicateRisk());
        self::assertFalse($result->outcomes()[1]->duplicateRisk);
        self::assertCount(1, $result->recoverable()->ambiguous());
    }

    /**
     * @return iterable<string, array{0: TransportFailureKind, 1: bool}>
     */
    public static function conservativeKinds(): iterable
    {
        yield 'connect failed' => [TransportFailureKind::ConnectFailed, true];
        yield 'name resolution failed' => [TransportFailureKind::NameResolutionFailed, true];
        yield 'tls handshake failed' => [TransportFailureKind::TlsFailed, true];
        yield 'certificate not verified' => [TransportFailureKind::TlsVerificationFailed, false];
        yield 'invalid configuration' => [TransportFailureKind::InvalidConfiguration, false];
        yield 'request construction' => [TransportFailureKind::RequestConstruction, false];
        yield 'timeout' => [TransportFailureKind::Timeout, false];
        yield 'interrupted' => [TransportFailureKind::Interrupted, false];
        yield 'unknown' => [TransportFailureKind::Unknown, false];
        yield 'client failure' => [TransportFailureKind::ClientFailure, false];
    }

    #[DataProvider('conservativeKinds')]
    public function testTheConservativePolicyRepeatsOnlyATransientPreTransmissionFailure(
        TransportFailureKind $kind,
        bool $repeats,
    ): void {
        $http = new FakeHttpClient();
        $http->queueFailure($kind);
        $http->queue(['data' => self::okTickets(1)]);

        $result = $this->expo($http, new ConservativeSendPolicy())->send(PushMessage::to(self::TOKEN_A));

        self::assertSame($repeats ? 2 : 1, $http->requestCount(), $kind->value);
        self::assertSame(
            $repeats ? Acceptance::Accepted : $result->outcomes()[0]->acceptance,
            $result->outcomes()[0]->acceptance,
            $kind->value
        );
    }

    public function testTheConservativePolicyNeverRepeatsWhatTheDeliveryPolicyStops(): void
    {
        foreach (self::conservativeKinds() as $case) {
            [$kind, $conservativeRepeats] = $case;

            $delivery = new FakeHttpClient();
            $delivery->queueFailure($kind);
            $delivery->queue(['data' => self::okTickets(1)]);

            $this->expo($delivery, new DeliveryRetryPolicy())->send(PushMessage::to(self::TOKEN_A));

            $deliveryRepeats = $delivery->requestCount() === 2;

            if ($conservativeRepeats) {
                self::assertTrue($deliveryRepeats, $kind->value);
            }
        }
    }

    /**
     * The backoff follows the documented schedule up to the cap, with no hidden
     * step limit.
     */
    public function testTheBackoffGrowsPastThirtyStepsUpToTheCap(): void
    {
        $settings = new RetrySettings(
            maxAttempts: 40,
            initialBackoffMs: 1,
            backoffMultiplier: 2.0,
            maxBackoffMs: 2_000_000_000,
        );

        self::assertSame(1, $settings->backoffFor(2));
        self::assertSame(1_073_741_824, $settings->backoffFor(32));
        // Before the fix every later attempt stopped here, below the cap.
        self::assertSame(2_000_000_000, $settings->backoffFor(33));
        self::assertSame(2_000_000_000, $settings->backoffFor(40));
    }

    public function testAHugeExponentStillGivesTheCap(): void
    {
        $settings = new RetrySettings(maxAttempts: 2_000, initialBackoffMs: 1_000, maxBackoffMs: 30_000);

        self::assertSame(30_000, $settings->backoffFor(1_000));
        self::assertSame(30_000, $settings->backoffFor(2_000));
    }

    public function testAMultiplierOfOneKeepsTheInitialBackoff(): void
    {
        $settings = new RetrySettings(maxAttempts: 50, initialBackoffMs: 250, backoffMultiplier: 1.0);

        self::assertSame(250, $settings->backoffFor(2));
        self::assertSame(250, $settings->backoffFor(40));
    }

    /**
     * An accepted ticket always carries its receipt ID, in storage as on the wire.
     */
    public function testAStoredAcceptedTicketWithoutAnIdIsRejected(): void
    {
        $stored = StorageEnvelope::wrap(PushTicket::STORAGE_TYPE, ['status' => 'ok']);

        $this->expectException(InvalidStorageException::class);
        $this->expectExceptionMessage('no receipt ID');

        PushTicket::fromStorageArray($stored);
    }

    public function testAStoredAcceptedTicketWithAnEmptyIdIsRejected(): void
    {
        $stored = StorageEnvelope::wrap(PushTicket::STORAGE_TYPE, ['status' => 'ok', 'id' => '']);

        $this->expectException(InvalidStorageException::class);
        $this->expectExceptionMessage('no receipt ID');

        PushTicket::fromStorageArray($stored);
    }

    public function testAStoredAcceptedTicketWithAWronglyTypedIdIsRejected(): void
    {
        $stored = StorageEnvelope::wrap(PushTicket::STORAGE_TYPE, ['status' => 'ok', 'id' => 42]);

        $this->expectException(InvalidStorageException::class);
        $this->expectExceptionMessage('no receipt ID');

        PushTicket::fromStorageArray($stored);
    }

    public function testAStoredErrorTicketNeedsNoId(): void
    {
        $stored = StorageEnvelope::wrap(PushTicket::STORAGE_TYPE, [
            'status' => 'error',
            'errorCode' => 'DeviceNotRegistered',
        ]);

        $ticket = PushTicket::fromStorageArray($stored);

        self::assertTrue($ticket->isError());
        self::assertNull($ticket->id);
        self::assertTrue($ticket->invalidatesToken());
    }
}
