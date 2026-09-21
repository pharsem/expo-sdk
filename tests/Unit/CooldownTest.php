<?php

declare(strict_types=1);

namespace Expo\Push\Tests\Unit;

use Expo\Push\Expo;
use Expo\Push\Observability\ChunkStarted;
use Expo\Push\PushMessage;
use Expo\Push\Result\Acceptance;
use Expo\Push\Result\FailureCategory;
use Expo\Push\Retry\DeliveryRetryPolicy;
use Expo\Push\Retry\RetrySettings;
use Expo\Push\Support\FixedJitter;
use Expo\Push\Tests\Support\FakeConcurrentHttpClient;
use Expo\Push\Tests\Support\FakeHttpClient;
use Expo\Push\Tests\Support\RecordingLimiter;
use Expo\Push\Tests\Support\RecordingObserver;
use Expo\Push\Tests\Support\TestCase;

/**
 * A `Retry-After` answer speaks for the whole project, not for one chunk.
 *
 * The reviewed scheduler shared only the local limiter denials. A server
 * cooldown held back the chunk that received it, and a fresh chunk walked
 * straight past it into the freed slot.
 */
final class CooldownTest extends TestCase
{
    /**
     * The scenario of the review, with concurrency two.
     *
     * A and B start. A receives 429 with `Retry-After: 5`. B succeeds. C must
     * not start before the five second boundary, and B keeps its ticket.
     */
    public function testAServerCooldownHoldsBackAChunkInTheFreedSlot(): void
    {
        $http = new FakeConcurrentHttpClient();
        $http->queue([], 429, 1, ['Retry-After' => '5']);
        $http->queue(['data' => [['status' => 'ok', 'id' => 'ticket-b']]]);
        $http->queue(['data' => [['status' => 'ok', 'id' => 'ticket-a']]]);
        $http->queue(['data' => [['status' => 'ok', 'id' => 'ticket-c']]]);

        $observer = new RecordingObserver();
        $result = $this->concurrent($http, $observer)->send(PushMessage::to(self::tokens(3))->title('Hi'));

        $starts = $this->startTimes($observer);

        self::assertSame(4, $http->requestCount());
        // A and B go out at once, and both retries of the bucket wait for the
        // boundary that the server named.
        self::assertSame([0, 0], [$starts[0][0], $starts[1][0]]);

        $cooldownEnd = 5_000;

        self::assertSame($cooldownEnd, $starts[0][1], 'the retry of chunk 0 waits for the cooldown');
        self::assertSame($cooldownEnd, $starts[2][0], 'chunk 2 must not start in the freed slot');

        // B finished before the cooldown and keeps its answer.
        self::assertSame('ticket-b', $result->outcomes()[1]->receiptId());
        self::assertCount(3, $result->accepted());
        self::assertSame([5_000], $this->sleeper->waits);
    }

    /**
     * A later, shorter cooldown never releases the bucket early.
     */
    public function testALaterShorterCooldownNeverShortensTheEarlierOne(): void
    {
        $http = new FakeConcurrentHttpClient();
        $http->queue([], 429, 1, ['Retry-After' => '8']);
        $http->queue([], 429, 1, ['Retry-After' => '2']);
        $http->queue(['data' => [['status' => 'ok', 'id' => 'ticket-a']]]);
        $http->queue(['data' => [['status' => 'ok', 'id' => 'ticket-b']]]);
        $http->queue(['data' => [['status' => 'ok', 'id' => 'ticket-c']]]);

        $observer = new RecordingObserver();

        self::ignoreResult($this->concurrent($http, $observer)->send(
            PushMessage::to(self::tokens(3))->title('Hi')
        ));

        $starts = $this->startTimes($observer);

        self::assertSame(8_000, $starts[0][1], 'the 8 second delay stands');
        self::assertSame(8_000, $starts[1][1], 'the 2 second answer never shortens it');
        self::assertSame(8_000, $starts[2][0], 'the third chunk obeys the longer delay');
    }

    /**
     * A cooldown that arrives while a chunk already waits pushes that chunk out.
     */
    public function testACooldownExtendsAnActiveRetryWait(): void
    {
        $settings = new RetrySettings(maxAttempts: 3, initialBackoffMs: 1_000);
        $http = new FakeConcurrentHttpClient();
        // Chunk 0 fails with a plain 500: its own backoff is one second.
        $http->queueRaw('boom', 500, 1);
        // Chunk 1 answers 429 with six seconds in the same poll.
        $http->queue([], 429, 1, ['Retry-After' => '6']);
        $http->queue(['data' => [['status' => 'ok', 'id' => 'ticket-a']]]);
        $http->queue(['data' => [['status' => 'ok', 'id' => 'ticket-b']]]);

        $observer = new RecordingObserver();

        self::ignoreResult($this->concurrent($http, $observer, new DeliveryRetryPolicy($settings))
            ->send(PushMessage::to(self::tokens(2))->title('Hi')));

        $starts = $this->startTimes($observer);

        // The one second backoff of chunk 0 gives way to the six seconds that
        // the server asked the project for.
        self::assertSame(6_000, $starts[0][1]);
        self::assertSame(6_000, $starts[1][1]);
    }

    /**
     * In continuation mode the later chunks still obey a cooldown that an
     * earlier chunk brought, even after that chunk ran out of retries.
     */
    public function testALaterChunkObeysACooldownOfAnExhaustedChunk(): void
    {
        $settings = new RetrySettings(maxAttempts: 1);
        $http = new FakeConcurrentHttpClient();
        $http->queue([], 429, 1, ['Retry-After' => '4']);
        $http->queue(['data' => [['status' => 'ok', 'id' => 'ticket-b']]]);

        $observer = new RecordingObserver();
        $result = $this->concurrent(
            $http,
            $observer,
            new DeliveryRetryPolicy($settings),
            concurrency: 1,
            continueAfterFailure: true,
        )->send(PushMessage::to(self::tokens(2))->title('Hi'));

        $starts = $this->startTimes($observer);

        self::assertSame(0, $starts[0][0]);
        self::assertSame(4_000, $starts[1][0], 'the second chunk waits for the cooldown of the first');
        self::assertSame('ticket-b', $result->outcomes()[1]->receiptId());
    }

    /**
     * In stop mode the unfinished work keeps the reason and the moment.
     */
    public function testStopModeKeepsTheCooldownAsRetryGuidance(): void
    {
        $settings = new RetrySettings(maxAttempts: 1);
        $http = (new FakeHttpClient())->queueRaw('slow down', 429, ['Retry-After' => '30']);

        $result = $this->expo($http, new DeliveryRetryPolicy($settings), sendChunkSize: 1)
            ->send(PushMessage::to(self::tokens(3))->title('Hi'));

        $expected = $this->clock->nowUtcMillis() + 30_000;

        self::assertSame(1, $http->requestCount());
        self::assertSame(FailureCategory::RateLimited, $result->requestFailures()[0]->category);
        self::assertSame(FailureCategory::Skipped, $result->requestFailures()[1]->category);

        foreach ($result->outcomes() as $outcome) {
            self::assertSame($expected, $outcome->earliestRetryAtUtcMs, 'index ' . $outcome->index);
        }

        self::assertSame($expected, $result->recoverable()->earliestRetryAtUtcMs);
        self::assertSame([1, 2], array_map(
            static fn (\Expo\Push\Result\NotificationOutcome $o): int => $o->index,
            $result->recoverable()->notAttempted()
        ));
    }

    /**
     * A cooldown that outlives the inline allowance defers the rest. It never
     * becomes a shorter wait, and it never becomes a long sleep either.
     */
    public function testALongCooldownDefersTheLaterChunksWithoutSleeping(): void
    {
        $http = (new FakeHttpClient())->queueRaw('slow down', 429, ['Retry-After' => '600']);

        $result = $this->expo($http, continueAfterFailure: true, sendChunkSize: 1)
            ->send(PushMessage::to(self::tokens(3))->title('Hi'));

        $expected = $this->clock->nowUtcMillis() + 600_000;

        self::assertSame(1, $http->requestCount());
        self::assertSame([], $this->sleeper->waits);
        self::assertCount(3, $result->requestFailures());

        foreach ($result->requestFailures() as $failure) {
            self::assertTrue($failure->deferred, 'chunk ' . $failure->chunkOrdinal);
            self::assertSame($expected, $failure->earliestRetryAtUtcMs, 'chunk ' . $failure->chunkOrdinal);
        }

        self::assertSame(FailureCategory::RateLimited, $result->requestFailures()[1]->category);
        self::assertSame(FailureCategory::RateLimited, $result->requestFailures()[2]->category);
    }

    /**
     * One operation holds one bucket, so a cooldown of one project never
     * reaches another.
     */
    public function testACooldownOfOneBucketNeverBlocksAnother(): void
    {
        $slow = (new FakeHttpClient())->queueRaw('slow down', 429, ['Retry-After' => '600']);
        $limiterA = new RecordingLimiter();

        self::ignoreResult($this->expo($slow, rateLimiter: $limiterA, bucket: 'project-a')
            ->send(PushMessage::to(self::TOKEN_A)->title('Hi')));

        $fast = (new FakeHttpClient())->queue(['data' => self::okTickets(1)]);
        $limiterB = new RecordingLimiter();
        $second = $this->expo($fast, rateLimiter: $limiterB, bucket: 'project-b')
            ->send(PushMessage::to(self::TOKEN_B)->title('Hi'));

        self::assertSame(['project-a'], $limiterA->buckets());
        self::assertSame(['project-b'], $limiterB->buckets());
        self::assertCount(1, $second->accepted());
        self::assertSame([], $this->sleeper->waits);
    }

    /**
     * A receipt lookup sends no notification, so it asks for no permit. A
     * cooldown of its own answers still applies to its later chunks.
     */
    public function testAReceiptLookupObeysItsOwnCooldownWithoutAskingForPermits(): void
    {
        $limiter = new RecordingLimiter();
        $http = new FakeHttpClient();
        $http->queueRaw('slow down', 429, ['Retry-After' => '3']);
        $http->queue(['data' => ['r-0' => ['status' => 'ok']]]);
        $http->queue(['data' => ['r-1' => ['status' => 'ok']]]);

        $expo = $this->expo(
            $http,
            rateLimiter: $limiter,
            bucket: 'project-a',
            continueAfterFailure: true,
            receiptChunkSize: 1,
        );

        $result = $expo->receipts(['r-0', 'r-1']);

        self::assertSame([], $limiter->calls, 'a lookup never spends a notification permit');
        self::assertSame([3_000], $this->sleeper->waits);
        self::assertSame(3, $http->requestCount());
        self::assertCount(2, $result->returnedIds());
    }

    private function concurrent(
        FakeConcurrentHttpClient $http,
        RecordingObserver $observer,
        ?DeliveryRetryPolicy $policy = null,
        int $concurrency = 2,
        bool $continueAfterFailure = true,
    ): Expo {
        return new Expo(
            httpClient: $http,
            retryPolicy: $policy,
            concurrency: $concurrency,
            observer: $observer,
            continueAfterFailure: $continueAfterFailure,
            sendChunkSize: 1,
            clock: $this->clock,
            sleeper: $this->sleeper,
            jitter: new FixedJitter(1.0),
        );
    }

    /**
     * The dispatch moments of every chunk, in milliseconds from the start.
     *
     * @return array<int, list<int>>
     */
    private function startTimes(RecordingObserver $observer): array
    {
        $start = 1_700_000_000_000;
        $times = [];

        foreach ($observer->events as $event) {
            if ($event instanceof ChunkStarted) {
                $times[$event->chunk][] = $event->atUtcMs - $start;
            }
        }

        return $times;
    }
}
