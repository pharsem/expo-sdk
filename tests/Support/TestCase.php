<?php

declare(strict_types=1);

namespace Expo\Push\Tests\Support;

use Expo\Push\Expo;
use Expo\Push\Http\HttpClient;
use Expo\Push\Observability\Observer;
use Expo\Push\PushToken;
use Expo\Push\RateLimit\RateLimiter;
use Expo\Push\Retry\RetryPolicy;
use Expo\Push\Support\FixedJitter;
use Expo\Push\Support\FrozenClock;
use Expo\Push\Support\RecordingSleeper;
use PHPUnit\Framework\TestCase as PhpUnitTestCase;

/**
 * The base of every test: a frozen clock, a recording sleeper and no jitter
 * surprise. No test sends a real notification and no test needs the network.
 */
abstract class TestCase extends PhpUnitTestCase
{
    protected const string TOKEN_A = 'ExponentPushToken[aaaaaaaaaaaaaaaaaaaaaa]';

    protected const string TOKEN_B = 'ExponentPushToken[bbbbbbbbbbbbbbbbbbbbbb]';

    protected const string TOKEN_C = 'ExponentPushToken[cccccccccccccccccccccc]';

    protected FrozenClock $clock;

    protected RecordingSleeper $sleeper;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->clock = new FrozenClock();
        $this->sleeper = new RecordingSleeper($this->clock);
    }

    protected function expo(
        HttpClient $http,
        ?RetryPolicy $retryPolicy = null,
        int $concurrency = 1,
        ?RateLimiter $rateLimiter = null,
        ?string $bucket = null,
        ?Observer $observer = null,
        bool $continueAfterFailure = false,
        ?int $operationDeadlineMs = null,
        float $jitter = 1.0,
        int $sendChunkSize = Expo::MESSAGE_CHUNK_LIMIT,
        int $receiptChunkSize = Expo::RECEIPT_CHUNK_LIMIT,
        bool $validateSize = true,
    ): Expo {
        return new Expo(
            httpClient: $http,
            retryPolicy: $retryPolicy,
            concurrency: $concurrency,
            rateLimiter: $rateLimiter,
            rateLimitBucket: $bucket,
            observer: $observer,
            validateSize: $validateSize,
            continueAfterFailure: $continueAfterFailure,
            operationDeadlineMs: $operationDeadlineMs,
            sendChunkSize: $sendChunkSize,
            receiptChunkSize: $receiptChunkSize,
            clock: $this->clock,
            sleeper: $this->sleeper,
            jitter: new FixedJitter($jitter),
        );
    }

    /**
     * Marks a value as used, and does nothing else.
     *
     * PHP 8.5 warns at the call site when a `#[\NoDiscard]` result goes unused,
     * so a test that expects the call to raise still has to use the value. The
     * PHP 8.5 `(void)` cast needs a newer floor than the `^8.3` of this package.
     */
    protected static function ignoreResult(mixed $value): void
    {
    }

    /**
     * @return list<string>
     */
    protected static function tokens(int $count, int $from = 0): array
    {
        return array_map(
            static fn (int $index): string => sprintf('ExponentPushToken[%022d]', $index),
            range($from, $from + $count - 1)
        );
    }

    /**
     * @return list<array{status: string, id: string}>
     */
    protected static function okTickets(int $count, string $prefix = 'ticket-'): array
    {
        if ($count === 0) {
            return [];
        }

        return array_map(
            static fn (int $index): array => ['status' => 'ok', 'id' => $prefix . $index],
            range(0, $count - 1)
        );
    }

    /**
     * @param list<PushToken> $tokens
     *
     * @return list<string>
     */
    protected static function values(array $tokens): array
    {
        return array_map(static fn (PushToken $token): string => $token->value, $tokens);
    }
}
