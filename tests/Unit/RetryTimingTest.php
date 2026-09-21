<?php

declare(strict_types=1);

namespace Expo\Push\Tests\Unit;

use Expo\Push\Http\TransportFailureKind;
use Expo\Push\PushMessage;
use Expo\Push\Result\Acceptance;
use Expo\Push\Result\FailureCategory;
use Expo\Push\Retry\DeliveryRetryPolicy;
use Expo\Push\Retry\NoRetryPolicy;
use Expo\Push\Retry\RetrySettings;
use Expo\Push\Tests\Support\FakeHttpClient;
use Expo\Push\Tests\Support\TestCase;

final class RetryTimingTest extends TestCase
{
    public function testTheDefaultPolicyMakesThreeAttempts(): void
    {
        $http = new FakeHttpClient();
        $http->queueRaw('boom', 500);
        $http->queueRaw('boom', 500);
        $http->queueRaw('boom', 500);

        $result = $this->expo($http)->send(PushMessage::to(self::TOKEN_A));

        self::assertSame(3, $http->requestCount());
        self::assertSame(3, $result->requestFailures()[0]->attemptCount());
        self::assertSame([1, 2, 3], array_map(
            static fn (\Expo\Push\Result\AttemptRecord $record): int => $record->number,
            $result->requestFailures()[0]->attempts
        ));
    }

    public function testTheBackoffDoublesAndTheJitterIsDeterministic(): void
    {
        $http = new FakeHttpClient();
        $http->queueRaw('boom', 500);
        $http->queueRaw('boom', 500);
        $http->queueRaw('boom', 500);

        $result = $this->expo($http)->send(PushMessage::to(self::TOKEN_A));

        self::assertCount(1, $result->requestFailures());
        self::assertSame([1000, 2000], $this->sleeper->waits);
    }

    public function testHalfJitterHalvesEveryWait(): void
    {
        $http = new FakeHttpClient();
        $http->queueRaw('boom', 500);
        $http->queueRaw('boom', 500);
        $http->queueRaw('boom', 500);

        $result = $this->expo($http, jitter: 0.5)->send(PushMessage::to(self::TOKEN_A));

        self::assertCount(1, $result->requestFailures());
        self::assertSame([500, 1000], $this->sleeper->waits);
    }

    public function testTheBackoffStopsAtTheLocalCap(): void
    {
        $settings = new RetrySettings(
            maxAttempts: 6,
            initialBackoffMs: 5_000,
            maxBackoffMs: 20_000,
            maxInlineWaitMs: 25_000,
            chunkBudgetMs: 200_000,
        );
        $http = new FakeHttpClient();

        for ($index = 0; $index < 6; ++$index) {
            $http->queueRaw('boom', 500);
        }

        $result = $this->expo($http, new DeliveryRetryPolicy($settings))->send(PushMessage::to(self::TOKEN_A));

        self::assertCount(1, $result->requestFailures());
        self::assertSame([5_000, 10_000, 20_000, 20_000, 20_000], $this->sleeper->waits);
    }

    public function testDisabledRetriesMakeExactlyOneAttempt(): void
    {
        $http = (new FakeHttpClient())->queueRaw('boom', 500);

        $result = $this->expo($http, new NoRetryPolicy())->send(PushMessage::to(self::TOKEN_A));

        self::assertSame(1, $http->requestCount());
        self::assertSame([], $this->sleeper->waits);
        self::assertSame(1, $result->requestFailures()[0]->attemptCount());
    }

    public function testANumericRetryAfterWinsOverTheLocalBackoff(): void
    {
        $http = new FakeHttpClient();
        $http->queueRaw('slow down', 429, ['Retry-After' => '7']);
        $http->queue(['data' => [['status' => 'ok', 'id' => 'ticket-1']]]);

        $result = $this->expo($http)->send(PushMessage::to(self::TOKEN_A));

        self::assertSame([7_000], $this->sleeper->waits);
        self::assertCount(1, $result->accepted());
    }

    public function testADateRetryAfterBecomesADelay(): void
    {
        $at = gmdate('D, d M Y H:i:s \G\M\T', intdiv($this->clock->nowUtcMillis(), 1000) + 5);
        $http = new FakeHttpClient();
        $http->queueRaw('slow down', 503, ['retry-after' => $at]);
        $http->queue(['data' => [['status' => 'ok', 'id' => 'ticket-1']]]);

        $result = $this->expo($http)->send(PushMessage::to(self::TOKEN_A));

        self::assertSame([5_000], $this->sleeper->waits);
        self::assertCount(1, $result->accepted());
    }

    public function testAMalformedRetryAfterFallsBackToTheLocalBackoff(): void
    {
        $http = new FakeHttpClient();
        $http->queueRaw('slow down', 429, ['Retry-After' => 'soon please']);
        $http->queue(['data' => [['status' => 'ok', 'id' => 'ticket-1']]]);

        $result = $this->expo($http)->send(PushMessage::to(self::TOKEN_A));

        self::assertSame([1_000], $this->sleeper->waits);
        self::assertCount(1, $result->accepted());
    }

    /**
     * The SDK never shortens a long server delay to a local cap. It defers
     * instead, and it says when a retry makes sense.
     */
    public function testALongRetryAfterBecomesADeferral(): void
    {
        $http = (new FakeHttpClient())->queueRaw('slow down', 429, ['Retry-After' => '120']);

        $result = $this->expo($http)->send(PushMessage::to(self::TOKEN_A));

        self::assertSame(1, $http->requestCount());
        self::assertSame([], $this->sleeper->waits);

        $failure = $result->requestFailures()[0];

        self::assertTrue($failure->deferred);
        self::assertTrue($failure->retryable);
        self::assertSame(FailureCategory::RateLimited, $failure->category);
        self::assertSame($this->clock->nowUtcMillis() + 120_000, $failure->earliestRetryAtUtcMs);
        self::assertSame(Acceptance::NotAccepted, $result->outcomes()[0]->acceptance);
        self::assertSame($failure->earliestRetryAtUtcMs, $result->earliestRetryAtUtcMs());
    }

    public function testAnExhaustedChunkBudgetDefersTheChunk(): void
    {
        $settings = new RetrySettings(
            maxAttempts: 5,
            initialBackoffMs: 4_000,
            maxInlineWaitMs: 10_000,
            chunkBudgetMs: 6_000,
        );
        $http = new FakeHttpClient();
        $http->queueRaw('boom', 500);
        $http->queueRaw('boom', 500);

        $result = $this->expo($http, new DeliveryRetryPolicy($settings))->send(PushMessage::to(self::TOKEN_A));

        self::assertSame(2, $http->requestCount());
        self::assertSame([4_000], $this->sleeper->waits);

        $failure = $result->requestFailures()[0];

        self::assertTrue($failure->deferred);
        self::assertSame(FailureCategory::Deadline, $failure->category);
        self::assertStringContainsString('chunk budget', $failure->message);
    }

    public function testTheRequestTimeoutShrinksWithTheRemainingBudget(): void
    {
        $settings = new RetrySettings(
            maxAttempts: 3,
            initialBackoffMs: 1_000,
            chunkBudgetMs: 2_500,
            requestTimeoutMs: 30_000,
        );
        $http = new FakeHttpClient();
        $http->queueRaw('boom', 500);
        $http->queue(['data' => [['status' => 'ok', 'id' => 'ticket-1']]]);

        $result = $this->expo($http, new DeliveryRetryPolicy($settings))->send(PushMessage::to(self::TOKEN_A));

        self::assertCount(1, $result->accepted());
        self::assertSame(2_500, $http->requests[0]->timeoutMs);
        self::assertSame(1_500, $http->requests[1]->timeoutMs);
    }

    public function testTheOperationDeadlineStopsTheLaterChunks(): void
    {
        $settings = new RetrySettings(maxAttempts: 2, initialBackoffMs: 3_000);
        $http = new FakeHttpClient();
        $http->queueRaw('boom', 500);
        $http->queue(['data' => self::okTickets(1)]);

        $result = $this->expo(
            $http,
            new DeliveryRetryPolicy($settings),
            operationDeadlineMs: 2_000,
            sendChunkSize: 1,
        )->send(PushMessage::to(self::tokens(2))->title('Hi'));

        self::assertSame(1, $http->requestCount());
        // The first chunk was on the wire, so its acceptance stays unknown. The
        // second chunk never started.
        self::assertSame(Acceptance::Unknown, $result->outcomes()[0]->acceptance);
        self::assertSame(Acceptance::NotAttempted, $result->outcomes()[1]->acceptance);
        self::assertSame(FailureCategory::Deadline, $result->requestFailures()[0]->category);
        self::assertSame(1, $result->requestFailures()[0]->attemptCount());
        self::assertSame(FailureCategory::Skipped, $result->requestFailures()[1]->category);
    }

    public function testATransportExceptionIsRetried(): void
    {
        $http = new FakeHttpClient();
        $http->queueFailure(TransportFailureKind::Interrupted);
        $http->queue(['data' => [['status' => 'ok', 'id' => 'ticket-1']]]);

        $result = $this->expo($http)->send(PushMessage::to(self::TOKEN_A));

        self::assertSame(2, $http->requestCount());
        self::assertCount(1, $result->accepted());
    }

    public function testACertificateFailureIsNeverRetried(): void
    {
        $http = (new FakeHttpClient())->queueFailure(TransportFailureKind::TlsVerificationFailed);

        $result = $this->expo($http)->send(PushMessage::to(self::TOKEN_A));

        self::assertSame(1, $http->requestCount());
        self::assertFalse($result->requestFailures()[0]->retryable);
    }

    public function testAnOrdinaryClientErrorIsNeverRetried(): void
    {
        $http = (new FakeHttpClient())->queue(['errors' => [['code' => 'UNAUTHORIZED', 'message' => 'no']]], 401);

        $result = $this->expo($http)->send(PushMessage::to(self::TOKEN_A));

        self::assertSame(1, $http->requestCount());
        self::assertFalse($result->requestFailures()[0]->retryable);
        self::assertSame(401, $result->requestFailures()[0]->httpStatus);
        self::assertSame('UNAUTHORIZED', $result->requestFailures()[0]->expoErrors[0]->code);
        self::assertSame(FailureCategory::Api, $result->requestFailures()[0]->category);
    }

    /**
     * A 503 with an HTML body still retries. The SDK classifies the status before
     * it needs a valid Expo body.
     */
    public function testANonJsonServerErrorStillRetries(): void
    {
        $http = new FakeHttpClient();
        $http->queueRaw('<html><body>502 Bad Gateway</body></html>', 502);
        $http->queue(['data' => [['status' => 'ok', 'id' => 'ticket-1']]]);

        $result = $this->expo($http)->send(PushMessage::to(self::TOKEN_A));

        self::assertSame(2, $http->requestCount());
        self::assertCount(1, $result->accepted());
    }

    public function testAnIndividualErrorTicketNeverReplaysTheWholeRequest(): void
    {
        $http = (new FakeHttpClient())->queue(['data' => [
            ['status' => 'ok', 'id' => 'ticket-1'],
            ['status' => 'error', 'message' => 'rate', 'details' => ['error' => 'MessageRateExceeded']],
        ]]);

        $result = $this->expo($http)->send(PushMessage::to([self::TOKEN_A, self::TOKEN_B]));

        self::assertSame(1, $http->requestCount());
        self::assertCount(1, $result->accepted());
        self::assertCount(1, $result->notAccepted());
    }
}
