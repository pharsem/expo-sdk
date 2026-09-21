<?php

declare(strict_types=1);

namespace Expo\Push\Tests\Unit;

use Expo\Push\Http\TransportFailureKind;
use Expo\Push\PushMessage;
use Expo\Push\Result\Acceptance;
use Expo\Push\Result\RecoverableWork;
use Expo\Push\Retry\NoRetryPolicy;
use Expo\Push\Tests\Support\FakeHttpClient;
use Expo\Push\Tests\Support\TestCase;

final class RecoveryTest extends TestCase
{
    public function testTheRecoverableWorkSeparatesSafeWorkFromAmbiguousWork(): void
    {
        $http = new FakeHttpClient();
        $http->queue(['data' => self::okTickets(2)]);
        $http->queueFailure(TransportFailureKind::Timeout);

        $result = $this->expo($http, new NoRetryPolicy(), sendChunkSize: 2)
            ->send(PushMessage::to(self::tokens(6))->title('Hi')->reference('batch-1'));

        $work = $result->recoverable();

        self::assertSame(4, $work->count());
        self::assertCount(2, $work->ambiguous());
        self::assertCount(2, $work->notAttempted());
        self::assertSame([2, 3, 4, 5], $work->indexes());
        self::assertSame(['batch-1'], $work->references());
        self::assertCount(4, $work->tokens());
        self::assertSame(
            [
                'total' => 4,
                'notAttempted' => 2,
                'ambiguous' => 2,
                'retryable' => 4,
                'needsIntervention' => 0,
            ],
            $work->summary()
        );
    }

    public function testACompleteSuccessLeavesNoWork(): void
    {
        $http = (new FakeHttpClient())->queue(['data' => self::okTickets(2)]);

        $work = $this->expo($http)->send(PushMessage::to([self::TOKEN_A, self::TOKEN_B]))->recoverable();

        self::assertTrue($work->isEmpty());
    }

    public function testAnAcceptedNotificationWithADuplicateRiskIsNotOpenWork(): void
    {
        $http = new FakeHttpClient();
        $http->queueFailure(TransportFailureKind::Timeout);
        $http->queue(['data' => self::okTickets(1)]);

        $result = $this->expo($http)->send(PushMessage::to(self::TOKEN_A));

        self::assertTrue($result->outcomes()[0]->duplicateRisk);
        self::assertTrue($result->recoverable()->isEmpty());
    }

    public function testTheWorkCarriesTheEarliestRetryTime(): void
    {
        $http = (new FakeHttpClient())->queueRaw('slow', 429, ['Retry-After' => '90']);

        $work = $this->expo($http)->send(PushMessage::to(self::TOKEN_A))->recoverable();

        // An empty worklist must never pass this test. A retry time with
        // nothing to retry is the exact shape of the bug that it guards.
        self::assertFalse($work->isEmpty());
        self::assertSame(1, $work->count());
        self::assertSame([0], $work->indexes());
        self::assertSame(self::TOKEN_A, $work->outcomes[0]->token->value);
        self::assertSame($this->clock->nowUtcMillis() + 90_000, $work->earliestRetryAtUtcMs);
        self::assertSame($this->clock->nowUtcMillis() + 90_000, $work->outcomes[0]->earliestRetryAtUtcMs);
    }

    public function testTheWorkRoundTripsThroughStorage(): void
    {
        $http = new FakeHttpClient();
        $http->queue(['data' => self::okTickets(1)]);
        $http->queueFailure(TransportFailureKind::Timeout);

        $result = $this->expo($http, new NoRetryPolicy(), sendChunkSize: 1)
            ->send(PushMessage::to(self::tokens(3))->title('Hi'));

        $json = json_encode($result->recoverable()->toStorageArray(), JSON_THROW_ON_ERROR);
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        self::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        $restored = RecoverableWork::fromStorageArray($decoded);

        self::assertSame(2, $restored->count());
        self::assertSame([1, 2], $restored->indexes());
        self::assertSame(Acceptance::Unknown, $restored->outcomes[0]->acceptance);
        self::assertTrue($restored->outcomes[0]->duplicateRisk);
        self::assertSame(Acceptance::NotAttempted, $restored->outcomes[1]->acceptance);
        self::assertFalse($restored->outcomes[1]->duplicateRisk);
        self::assertSame(self::tokens(3)[1], $restored->outcomes[0]->token->value);
    }

    public function testAProviderErrorCodeAdmitsUncertainty(): void
    {
        self::assertNull(\Expo\Push\PushError::ProviderError->maySucceedLater());
        self::assertNull(\Expo\Push\PushError::ExpoError->maySucceedLater());
        self::assertTrue(\Expo\Push\PushError::MessageRateExceeded->maySucceedLater());
        self::assertFalse(\Expo\Push\PushError::DeviceNotRegistered->maySucceedLater());
        self::assertFalse(\Expo\Push\PushError::MessageTooBig->maySucceedLater());
        self::assertFalse(\Expo\Push\PushError::InvalidCredentials->maySucceedLater());
    }

    public function testOnlyDeviceNotRegisteredInvalidatesAToken(): void
    {
        foreach (\Expo\Push\PushError::cases() as $error) {
            self::assertSame(
                $error === \Expo\Push\PushError::DeviceNotRegistered,
                $error->invalidatesToken(),
                $error->value
            );
        }
    }

    public function testACredentialErrorIsNotATokenProblem(): void
    {
        $http = (new FakeHttpClient())->queue(['data' => [
            ['status' => 'error', 'message' => 'bad key', 'details' => ['error' => 'InvalidCredentials']],
        ]]);

        $result = $this->expo($http)->send(PushMessage::to(self::TOKEN_A));

        self::assertSame([], $result->unregisteredTokens());
        self::assertSame(
            \Expo\Push\ErrorClassification::Credentials,
            $result->outcomes()[0]->ticket?->classification()
        );
    }
}
