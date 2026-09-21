<?php

declare(strict_types=1);

namespace Expo\Push\Retry;

use Expo\Push\Exception\InvalidConfigurationException;

/**
 * The numbers that bound every attempt.
 *
 * Every value is in milliseconds, and `maxAttempts` counts the first attempt.
 * `maxAttempts: 1` means one attempt and no retry.
 *
 * These are the defaults of this SDK. Expo does not require them.
 */
final readonly class RetrySettings
{
    /**
     * @param int   $maxAttempts       attempts for one chunk, the first attempt included
     * @param int   $initialBackoffMs  the backoff before the second attempt
     * @param float $backoffMultiplier how much the backoff grows for each attempt
     * @param int   $maxBackoffMs      the largest local backoff, before the jitter
     * @param int   $maxInlineWaitMs   the longest wait that the SDK does inside the call. A longer wait becomes a deferral
     * @param int   $chunkBudgetMs     the whole life of one chunk: the limiter waits, the attempts and the retry waits
     * @param int   $connectTimeoutMs  the connection timeout of one attempt
     * @param int   $requestTimeoutMs  the total timeout of one attempt
     *
     * @throws InvalidConfigurationException when a value cannot work
     */
    public function __construct(
        public int $maxAttempts = 3,
        public int $initialBackoffMs = 1_000,
        public float $backoffMultiplier = 2.0,
        public int $maxBackoffMs = 30_000,
        public int $maxInlineWaitMs = 10_000,
        public int $chunkBudgetMs = 60_000,
        public int $connectTimeoutMs = 10_000,
        public int $requestTimeoutMs = 30_000,
    ) {
        self::assert($maxAttempts >= 1, 'maxAttempts must be 1 or more. One means no retry.');
        self::assert($initialBackoffMs >= 0, 'initialBackoffMs must be zero or more.');
        self::assert($backoffMultiplier >= 1.0, 'backoffMultiplier must be 1.0 or more.');
        self::assert(is_finite($backoffMultiplier), 'backoffMultiplier must be a finite number.');
        self::assert($maxBackoffMs >= 0, 'maxBackoffMs must be zero or more.');
        self::assert($maxInlineWaitMs >= 0, 'maxInlineWaitMs must be zero or more.');
        self::assert($chunkBudgetMs >= 1, 'chunkBudgetMs must be 1 or more.');
        self::assert($connectTimeoutMs >= 1, 'connectTimeoutMs must be 1 or more.');
        self::assert($requestTimeoutMs >= 1, 'requestTimeoutMs must be 1 or more.');
        self::assert(
            $connectTimeoutMs <= $requestTimeoutMs,
            'connectTimeoutMs must not be above requestTimeoutMs.'
        );
    }

    /**
     * The defaults of the SDK: three attempts, one second of backoff, a 30 second
     * cap, a 10 second inline wait and a 60 second chunk budget.
     */
    public static function defaults(): self
    {
        return new self();
    }

    /**
     * Exactly one attempt for each chunk.
     */
    public static function noRetries(): self
    {
        return new self(maxAttempts: 1);
    }

    /**
     * A copy with another attempt count.
     */
    public function withMaxAttempts(int $maxAttempts): self
    {
        return new self(
            $maxAttempts,
            $this->initialBackoffMs,
            $this->backoffMultiplier,
            $this->maxBackoffMs,
            $this->maxInlineWaitMs,
            $this->chunkBudgetMs,
            $this->connectTimeoutMs,
            $this->requestTimeoutMs,
        );
    }

    /**
     * The local backoff before attempt number `$next`, before the jitter.
     *
     * Attempt 2 waits `initialBackoffMs`. Attempt 3 waits that times the
     * multiplier, and so on, up to `maxBackoffMs`.
     */
    public function backoffFor(int $next): int
    {
        if ($next <= 1 || $this->initialBackoffMs === 0) {
            return 0;
        }

        // The exponent grows without a clamp. An overflow becomes INF, and the
        // check below turns both INF and a large value into the cap.
        $delay = $this->initialBackoffMs * ($this->backoffMultiplier ** ($next - 2));

        if (!is_finite($delay) || $delay > $this->maxBackoffMs) {
            return $this->maxBackoffMs;
        }

        return (int) round($delay);
    }

    private static function assert(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new InvalidConfigurationException('Invalid retry settings: ' . $message);
        }
    }
}
