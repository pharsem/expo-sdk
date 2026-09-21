<?php

declare(strict_types=1);

namespace Expo\Push\Tests\Unit;

use Expo\Push\Exception\InvalidConfigurationException;
use Expo\Push\Expo;
use Expo\Push\PushMessage;
use Expo\Push\RateLimit\SlidingWindowRateLimiter;
use Expo\Push\Result\Acceptance;
use Expo\Push\Result\FailureCategory;
use Expo\Push\Retry\DeliveryRetryPolicy;
use Expo\Push\Retry\RetrySettings;
use Expo\Push\Support\FrozenClock;
use Expo\Push\Tests\Support\FakeHttpClient;
use Expo\Push\Tests\Support\RecordingLimiter;
use Expo\Push\Tests\Support\TestCase;

final class RateLimitTest extends TestCase
{
    public function testTheLimiterCountsNotificationsAndNotRequests(): void
    {
        $http = new FakeHttpClient();
        $http->queue(['data' => self::okTickets(3)]);
        $http->queue(['data' => self::okTickets(1)]);

        $limiter = new RecordingLimiter();

        $result = $this->expo($http, rateLimiter: $limiter, bucket: 'project-a', sendChunkSize: 3)
            ->send(PushMessage::to(self::tokens(4))->title('Hi'));

        self::assertCount(4, $result->accepted());
        self::assertSame([3, 1], $limiter->permits());
        self::assertSame(['project-a', 'project-a'], $limiter->buckets());
    }

    public function testEveryRetryTakesItsOwnPermits(): void
    {
        $http = new FakeHttpClient();
        $http->queueRaw('boom', 500);
        $http->queue(['data' => self::okTickets(2)]);

        $limiter = new RecordingLimiter();

        $result = $this->expo($http, rateLimiter: $limiter, bucket: 'p')
            ->send(PushMessage::to([self::TOKEN_A, self::TOKEN_B]));

        self::assertCount(2, $result->accepted());
        self::assertSame([2, 2], $limiter->permits());
    }

    public function testADeniedPermitBecomesAWaitAndThenASend(): void
    {
        $http = (new FakeHttpClient())->queue(['data' => self::okTickets(1)]);
        $limiter = (new RecordingLimiter())->deny(250);

        $result = $this->expo($http, rateLimiter: $limiter, bucket: 'p')
            ->send(PushMessage::to(self::TOKEN_A));

        self::assertSame([250], $this->sleeper->waits);
        self::assertCount(1, $result->accepted());
        self::assertSame(1, $http->requestCount());
        self::assertCount(2, $limiter->calls);
    }

    /**
     * A cooldown of the bucket holds back every chunk of that project, not only
     * the chunk that the limiter refused.
     */
    public function testACooldownHoldsTheWholeBucket(): void
    {
        $http = new FakeHttpClient();
        $http->queue(['data' => self::okTickets(1)]);
        $http->queue(['data' => self::okTickets(1)]);

        $limiter = (new RecordingLimiter())->deny(300);

        $result = $this->expo($http, rateLimiter: $limiter, bucket: 'p', sendChunkSize: 1)
            ->send(PushMessage::to([self::TOKEN_A, self::TOKEN_B]));

        self::assertCount(2, $result->accepted());
        self::assertSame([300], $this->sleeper->waits);
        // The SDK asked again for the refused chunk and then for the next one.
        self::assertSame([1, 1, 1], $limiter->permits());
    }

    public function testALongLimiterWaitBecomesADeferral(): void
    {
        $http = new FakeHttpClient();
        $limiter = (new RecordingLimiter())->deny(60_000);

        $result = $this->expo($http, rateLimiter: $limiter, bucket: 'p')
            ->send(PushMessage::to(self::TOKEN_A));

        self::assertSame(0, $http->requestCount());
        self::assertSame([], $this->sleeper->waits);

        $failure = $result->requestFailures()[0];

        self::assertSame(FailureCategory::RateLimited, $failure->category);
        self::assertTrue($failure->deferred);
        self::assertSame($this->clock->nowUtcMillis() + 60_000, $failure->earliestRetryAtUtcMs);
        self::assertSame(Acceptance::NotAttempted, $result->outcomes()[0]->acceptance);
    }

    public function testALimiterOutageFailsClosedAndKeepsTheEarlierResults(): void
    {
        $http = new FakeHttpClient();
        $http->queue(['data' => self::okTickets(1)]);

        $limiter = new RecordingLimiter(failAfterFirst: true);

        $result = $this->expo($http, rateLimiter: $limiter, bucket: 'p', sendChunkSize: 1)
            ->send(PushMessage::to([self::TOKEN_A, self::TOKEN_B]));

        self::assertSame(1, $http->requestCount());
        self::assertCount(1, $result->accepted());
        self::assertSame(Acceptance::NotAttempted, $result->outcomes()[1]->acceptance);
        self::assertSame(FailureCategory::Limiter, $result->requestFailures()[0]->category);
        self::assertStringContainsString('rate limiter failed', $result->requestFailures()[0]->message);
    }

    public function testALimiterNeedsABucket(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('rateLimitBucket');

        new Expo(httpClient: new FakeHttpClient(), rateLimiter: new SlidingWindowRateLimiter());
    }

    public function testAChunkThatCanNeverFitTheLimiterIsRejected(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('can never fit');

        new Expo(
            httpClient: new FakeHttpClient(),
            rateLimiter: new SlidingWindowRateLimiter(permitsPerWindow: 50),
            rateLimitBucket: 'p',
            sendChunkSize: 100,
        );
    }

    public function testTheSlidingWindowGrantsUpToItsCapacity(): void
    {
        $clock = new FrozenClock();
        $limiter = new SlidingWindowRateLimiter(600, 1_000, $clock);

        self::assertTrue($limiter->acquire('p', 400)->granted);
        self::assertTrue($limiter->acquire('p', 200)->granted);

        $denied = $limiter->acquire('p', 1);

        self::assertFalse($denied->granted);
        self::assertSame(1_000, $denied->retryAfterMs);
        self::assertSame(0, $limiter->available('p'));
    }

    /**
     * A fixed window lets 600 through at 0.999 s and 600 more at 1.001 s. A
     * sliding window does not.
     */
    public function testTheWindowSlidesAndDoesNotBurstAtTheBoundary(): void
    {
        $clock = new FrozenClock();
        $limiter = new SlidingWindowRateLimiter(600, 1_000, $clock);

        self::assertTrue($limiter->acquire('p', 600)->granted);

        $clock->advance(999);

        self::assertFalse($limiter->acquire('p', 600)->granted);

        $clock->advance(2);

        self::assertTrue($limiter->acquire('p', 600)->granted);
    }

    public function testTheWindowFreesThePermitsOfTheOldestCall(): void
    {
        $clock = new FrozenClock();
        $limiter = new SlidingWindowRateLimiter(100, 1_000, $clock);

        $limiter->acquire('p', 60);
        $clock->advance(400);
        $limiter->acquire('p', 40);

        self::assertFalse($limiter->acquire('p', 10)->granted);
        self::assertSame(600, $limiter->acquire('p', 10)->retryAfterMs);

        $clock->advance(601);

        self::assertTrue($limiter->acquire('p', 60)->granted);
    }

    public function testEachProjectKeepsItsOwnWindow(): void
    {
        $clock = new FrozenClock();
        $limiter = new SlidingWindowRateLimiter(100, 1_000, $clock);

        self::assertTrue($limiter->acquire('project-a', 100)->granted);
        self::assertFalse($limiter->acquire('project-a', 1)->granted);
        self::assertTrue($limiter->acquire('project-b', 100)->granted);
    }

    public function testARequestAboveTheCapacityRaisesRatherThanWaitForever(): void
    {
        $limiter = new SlidingWindowRateLimiter(50, 1_000, new FrozenClock());

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('can never fit');

        $limiter->acquire('p', 51);
    }

    public function testTheInProcessLimiterPacesARealSend(): void
    {
        $http = new FakeHttpClient();
        $http->queue(['data' => self::okTickets(2)]);
        $http->queue(['data' => self::okTickets(2)]);

        $limiter = new SlidingWindowRateLimiter(2, 1_000, $this->clock);
        $settings = new RetrySettings(maxInlineWaitMs: 5_000);

        $result = $this->expo(
            $http,
            new DeliveryRetryPolicy($settings),
            rateLimiter: $limiter,
            bucket: 'project-a',
            sendChunkSize: 2,
        )->send(PushMessage::to(self::tokens(4))->title('Hi'));

        self::assertCount(4, $result->accepted());
        self::assertSame([1_000], $this->sleeper->waits);
    }

    public function testTheDefaultRateIsSixHundredPerSecond(): void
    {
        self::assertSame(600, SlidingWindowRateLimiter::EXPO_NOTIFICATIONS_PER_SECOND);
        self::assertSame(600, (new SlidingWindowRateLimiter())->capacity());
    }
}
