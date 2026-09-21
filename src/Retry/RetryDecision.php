<?php

declare(strict_types=1);

namespace Expo\Push\Retry;

/**
 * The answer of a retry policy for one finished attempt.
 */
final readonly class RetryDecision
{
    private function __construct(
        public bool $retry,
        public string $reason,
    ) {
    }

    public static function retry(string $reason): self
    {
        return new self(true, $reason);
    }

    public static function stop(string $reason): self
    {
        return new self(false, $reason);
    }
}
