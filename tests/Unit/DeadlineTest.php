<?php

declare(strict_types=1);

namespace Expo\Push\Tests\Unit;

use Expo\Push\Expo;
use Expo\Push\Observability\ChunkStarted;
use Expo\Push\PushMessage;
use Expo\Push\Result\Acceptance;
use Expo\Push\Result\FailureCategory;
use Expo\Push\Result\RecoveryDisposition;
use Expo\Push\Retry\DeliveryRetryPolicy;
use Expo\Push\Retry\RetrySettings;
use Expo\Push\Support\FixedJitter;
use Expo\Push\Tests\Support\FakeConcurrentHttpClient;
use Expo\Push\Tests\Support\FakeHttpClient;
use Expo\Push\Tests\Support\RecordingLimiter;
use Expo\Push\Tests\Support\RecordingObserver;
use Expo\Push\Tests\Support\SlowLimiter;
use Expo\Push\Tests\Support\TestCase;

/**
 * A deadline bounds every wait and every dispatch.
 *
 * The reviewed scheduler slept until the next retry without looking at the
 * operation deadline, so a 100 millisecond call could sleep five seconds. It
 * also let a chunk build another request while its budget was already spent.
 */
final class DeadlineTest extends TestCase
{
    /**
     * The scenario of the review: a 100 ms deadline and a five second
     * `Retry-After`.
     */
    public function testAShortDeadlineStopsTheWaitAndSendsNothingAgain(): void
    {
        $http = (new FakeHttpClient())->queueRaw('slow down', 429, ['Retry-After' => '5']);

        $started = $this->clock->monotonicMillis();
        $result = $this->expo($http, operationDeadlineMs: 100)
            ->send(PushMessage::to(self::TOKEN_A)->title('Hi'));
        $elapsed = $this->clock->monotonicMillis() - $started;

        self::assertSame(100, $elapsed, 'the call returns at its own deadline');
        self::assertSame([100], $this->sleeper->waits);
        self::assertSame(1, $http->requestCount(), 'no second dispatch');

        $failure = $result->requestFailures()[0];

        self::assertSame(FailureCategory::Deadline, $failure->category);
        self::assertTrue($failure->deferred);
        self::assertSame(1, $failure->attemptCount());
        self::assertSame(429, $failure->attempts[0]->status);
        // The server asked for five seconds, and the deadline never shortens
        // that. The work waits until the moment that the server named.
        self::assertSame($this->clock->nowUtcMillis() + 4_900, $failure->earliestRetryAtUtcMs);

        $outcome = $result->outcomes()[0];

        self::assertSame(Acceptance::NotAccepted, $outcome->acceptance);
        self::assertSame(RecoveryDisposition::Retryable, $outcome->recovery);
        self::assertSame(1, $result->recoverable()->count());
    }

    /**
     * The request timeout never outlives the deadline, and never the budget.
     */
    public function testTheRequestTimeoutFollowsTheStrictestBudget(): void
    {
        $settings = new RetrySettings(chunkBudgetMs: 4_000, requestTimeoutMs: 30_000);
        $http = (new FakeHttpClient())->queue(['data' => self::okTickets(1)]);

        self::ignoreResult($this->expo($http, new DeliveryRetryPolicy($settings), operationDeadlineMs: 1_500)
            ->send(PushMessage::to(self::TOKEN_A)->title('Hi')));

        self::assertSame(1_500, $http->requests[0]->timeoutMs);

        $shorter = new RetrySettings(chunkBudgetMs: 700, requestTimeoutMs: 30_000);
        $second = (new FakeHttpClient())->queue(['data' => self::okTickets(1)]);

        self::ignoreResult($this->expo($second, new DeliveryRetryPolicy($shorter), operationDeadlineMs: 1_500)
            ->send(PushMessage::to(self::TOKEN_A)->title('Hi')));

        self::assertSame(700, $second->requests[0]->timeoutMs);
    }

    /**
     * A limiter that blocks past the deadline must not free a dispatch.
     *
     * The budgets get a second look after every call that can take time.
     */
    public function testABlockingLimiterThatEatsTheDeadlineStopsTheDispatch(): void
    {
        $http = new FakeHttpClient();
        $limiter = new SlowLimiter($this->clock, 500);

        $result = $this->expo($http, rateLimiter: $limiter, bucket: 'p', operationDeadlineMs: 100)
            ->send(PushMessage::to(self::TOKEN_A)->title('Hi'));

        self::assertCount(1, $limiter->calls);
        self::assertSame(0, $http->requestCount(), 'no request starts with a spent budget');
        self::assertSame(Acceptance::NotAttempted, $result->outcomes()[0]->acceptance);
        self::assertSame(FailureCategory::Deadline, $result->requestFailures()[0]->category);
        self::assertTrue($result->requestFailures()[0]->deferred);
    }

    /**
     * The same guard holds for the budget of one chunk.
     */
    public function testABlockingLimiterThatEatsTheChunkBudgetStopsTheDispatch(): void
    {
        $settings = new RetrySettings(chunkBudgetMs: 200);
        $http = new FakeHttpClient();
        $limiter = new SlowLimiter($this->clock, 900);

        $result = $this->expo($http, new DeliveryRetryPolicy($settings), rateLimiter: $limiter, bucket: 'p')
            ->send(PushMessage::to(self::TOKEN_A)->title('Hi'));

        self::assertSame(0, $http->requestCount());
        self::assertSame(Acceptance::NotAttempted, $result->outcomes()[0]->acceptance);
        self::assertSame(FailureCategory::Deadline, $result->requestFailures()[0]->category);
    }

    /**
     * A limiter wait never runs past the deadline either.
     */
    public function testALimiterWaitStopsAtTheDeadline(): void
    {
        $http = new FakeHttpClient();
        $limiter = (new RecordingLimiter())->deny(5_000);

        $started = $this->clock->monotonicMillis();
        $result = $this->expo($http, rateLimiter: $limiter, bucket: 'p', operationDeadlineMs: 250)
            ->send(PushMessage::to(self::TOKEN_A)->title('Hi'));
        $elapsed = $this->clock->monotonicMillis() - $started;

        self::assertSame(250, $elapsed);
        self::assertSame([250], $this->sleeper->waits);
        self::assertSame(0, $http->requestCount());
        self::assertSame(Acceptance::NotAttempted, $result->outcomes()[0]->acceptance);
        // The limiter asked for five seconds, and that moment survives the
        // deadline.
        self::assertSame($this->clock->nowUtcMillis() + 4_750, $result->requestFailures()[0]->earliestRetryAtUtcMs);
    }

    /**
     * A shared server cooldown also stops at the deadline, and never before the
     * moment that the server asked for.
     */
    public function testASharedCooldownStopsAtTheDeadlineAndKeepsItsMoment(): void
    {
        $http = new FakeConcurrentHttpClient();
        $http->queue([], 429, 1, ['Retry-After' => '5']);
        $http->queue(['data' => [['status' => 'ok', 'id' => 'ticket-b']]]);

        $observer = new RecordingObserver();
        $expo = new Expo(
            httpClient: $http,
            concurrency: 2,
            observer: $observer,
            continueAfterFailure: true,
            operationDeadlineMs: 300,
            sendChunkSize: 1,
            clock: $this->clock,
            sleeper: $this->sleeper,
            jitter: new FixedJitter(1.0),
        );

        $started = $this->clock->monotonicMillis();
        $result = $expo->send(PushMessage::to(self::tokens(3))->title('Hi'));
        $elapsed = $this->clock->monotonicMillis() - $started;

        self::assertSame(300, $elapsed);
        self::assertSame(2, $http->requestCount(), 'the third chunk never starts');
        self::assertCount(2, $this->startsOf($observer));

        // Chunk 1 was already on the wire and keeps its ticket.
        self::assertSame('ticket-b', $result->outcomes()[1]->receiptId());
        // Chunk 2 never went out, and it inherits the cooldown of the bucket.
        self::assertSame(Acceptance::NotAttempted, $result->outcomes()[2]->acceptance);
        self::assertSame(
            $this->clock->nowUtcMillis() + 4_700,
            $result->outcome(2)?->earliestRetryAtUtcMs
        );
    }

    /**
     * A chunk that was on the wire keeps whatever the answers proved.
     */
    public function testADeadlineNeverErasesTheEvidenceOfAnEarlierAttempt(): void
    {
        $settings = new RetrySettings(maxAttempts: 3, initialBackoffMs: 4_000);
        $http = new FakeHttpClient();
        $http->queueRaw('boom', 500);

        $result = $this->expo($http, new DeliveryRetryPolicy($settings), operationDeadlineMs: 400, sendChunkSize: 1)
            ->send(PushMessage::to(self::tokens(2))->title('Hi'));

        $first = $result->outcomes()[0];

        // A 500 after transmission stays ambiguous, whatever ended the call.
        self::assertSame(Acceptance::Unknown, $first->acceptance);
        self::assertTrue($first->duplicateRisk);
        self::assertSame(1, $result->requestFailures()[0]->attemptCount());
        self::assertSame(500, $result->requestFailures()[0]->attempts[0]->status);
        // The backoff of the chunk survives as the retry guidance.
        self::assertSame($this->clock->nowUtcMillis() + 3_600, $result->requestFailures()[0]->earliestRetryAtUtcMs);

        // The chunk that never went out says so, and nothing more.
        self::assertSame(Acceptance::NotAttempted, $result->outcomes()[1]->acceptance);
        self::assertFalse($result->outcomes()[1]->duplicateRisk);
    }

    /**
     * An expired deadline finalizes the work at once. It never spins.
     */
    public function testAnExpiredDeadlineNeverSpins(): void
    {
        $settings = new RetrySettings(maxAttempts: 5, initialBackoffMs: 2_000);
        $http = new FakeHttpClient();
        $http->queueRaw('boom', 500);

        $result = $this->expo($http, new DeliveryRetryPolicy($settings), operationDeadlineMs: 50, sendChunkSize: 1)
            ->send(PushMessage::to(self::tokens(4))->title('Hi'));

        // One short sleep, and then the whole operation ends.
        self::assertSame([50], $this->sleeper->waits);
        self::assertSame(1, $http->requestCount());
        self::assertCount(4, $result->requestFailures());
        self::assertCount(3, $result->notAttempted());
    }

    /**
     * Two chunks with different budgets each stop at their own.
     */
    public function testEachChunkStopsAtItsOwnBudget(): void
    {
        $settings = new RetrySettings(maxAttempts: 3, initialBackoffMs: 1_000, chunkBudgetMs: 900);
        $http = new FakeHttpClient();
        $http->queueRaw('boom', 500);
        $http->queueRaw('boom', 500);

        $result = $this->expo(
            $http,
            new DeliveryRetryPolicy($settings),
            continueAfterFailure: true,
            sendChunkSize: 1,
        )->send(PushMessage::to(self::tokens(2))->title('Hi'));

        // Each chunk gets one attempt: the one second backoff does not fit the
        // 900 millisecond budget, so each one defers on its own.
        self::assertSame(2, $http->requestCount());
        self::assertSame([], $this->sleeper->waits);
        self::assertCount(2, $result->requestFailures());

        foreach ($result->requestFailures() as $failure) {
            self::assertSame(FailureCategory::Deadline, $failure->category);
            self::assertTrue($failure->deferred);
            self::assertStringContainsString('chunk budget', $failure->message);
        }
    }

    /**
     * Two chunks that became active at different moments each keep their own
     * remaining budget.
     */
    public function testConcurrentChunksKeepTheirOwnRemainingBudget(): void
    {
        $settings = new RetrySettings(
            maxAttempts: 3,
            initialBackoffMs: 1_000,
            chunkBudgetMs: 4_000,
            requestTimeoutMs: 30_000,
        );
        $http = new FakeHttpClient();
        // Chunk 0 fails once and then succeeds on its retry.
        $http->queueRaw('boom', 500);
        $http->queue(['data' => self::okTickets(1)]);
        // Chunk 1 starts only after that wait, with its whole budget.
        $http->queue(['data' => self::okTickets(1)]);

        $result = $this->expo($http, new DeliveryRetryPolicy($settings), sendChunkSize: 1)
            ->send(PushMessage::to(self::tokens(2))->title('Hi'));

        self::assertSame([1_000], $this->sleeper->waits);
        self::assertSame(3, $http->requestCount());
        // The first attempt of chunk 0 holds the whole budget.
        self::assertSame(4_000, $http->requests[0]->timeoutMs);
        // Its retry holds what the one second wait left.
        self::assertSame(3_000, $http->requests[1]->timeoutMs);
        // Chunk 1 became active at t=1000, so its own budget starts there.
        self::assertSame(4_000, $http->requests[2]->timeoutMs);
        self::assertCount(2, $result->accepted());
    }

    /**
     * A deadline in the past never starts anything at all.
     */
    public function testADeadlineOfOneMillisecondStartsNothingButStillAnswers(): void
    {
        $settings = new RetrySettings(connectTimeoutMs: 1, requestTimeoutMs: 1);
        $http = (new FakeHttpClient())->queue(['data' => self::okTickets(1)]);

        $result = $this->expo($http, new DeliveryRetryPolicy($settings), operationDeadlineMs: 1)
            ->send(PushMessage::to(self::TOKEN_A)->title('Hi'));

        // The deadline has not passed at the first activation, so the request
        // goes out with a one millisecond timeout.
        self::assertSame(1, $http->requestCount());
        self::assertSame(1, $http->requests[0]->timeoutMs);
        self::assertCount(1, $result->accepted());
    }

    /**
     * @return list<int>
     */
    private function startsOf(RecordingObserver $observer): array
    {
        $chunks = [];

        foreach ($observer->events as $event) {
            if ($event instanceof ChunkStarted) {
                $chunks[] = $event->chunk;
            }
        }

        return $chunks;
    }
}
