<?php

declare(strict_types=1);

namespace Expo\Push\RateLimit;

/**
 * The answer of a rate limiter for one request.
 */
final readonly class PermitDecision
{
    private function __construct(
        public bool $granted,
        public int $retryAfterMs,
    ) {
    }

    /**
     * The chunk may go out now.
     */
    public static function granted(): self
    {
        return new self(true, 0);
    }

    /**
     * The chunk must wait. The SDK owns the waiting, not the limiter.
     *
     * @param int $retryAfterMs how long to wait before asking again
     */
    public static function wait(int $retryAfterMs): self
    {
        return new self(false, max(0, $retryAfterMs));
    }
}
