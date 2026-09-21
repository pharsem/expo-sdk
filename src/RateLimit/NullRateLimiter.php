<?php

declare(strict_types=1);

namespace Expo\Push\RateLimit;

/**
 * Grants every request. This is the default: limiting is opt in.
 */
final readonly class NullRateLimiter implements RateLimiter
{
    #[\Override]
    public function acquire(string $bucket, int $permits): PermitDecision
    {
        return PermitDecision::granted();
    }

    #[\Override]
    public function capacity(): ?int
    {
        return null;
    }
}
