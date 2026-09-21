<?php

declare(strict_types=1);

namespace Expo\Push\Tests\Support;

use Expo\Push\RateLimit\PermitDecision;
use Expo\Push\RateLimit\RateLimiter;
use Expo\Push\Support\FrozenClock;

/**
 * A limiter that takes real time to answer, and grants in the end.
 *
 * A shared limiter talks to Redis or to a database, so `acquire()` can block.
 * The SDK has to look at the budgets again after such a call. This limiter
 * moves a frozen clock instead of blocking, so a test can prove that it does.
 */
final class SlowLimiter implements RateLimiter
{
    /** @var list<array{bucket: string, permits: int}> */
    public array $calls = [];

    public function __construct(
        private readonly FrozenClock $clock,
        private readonly int $costMs,
        private readonly ?int $capacity = null,
    ) {
    }

    #[\Override]
    public function acquire(string $bucket, int $permits): PermitDecision
    {
        $this->calls[] = ['bucket' => $bucket, 'permits' => $permits];
        $this->clock->advance($this->costMs);

        return PermitDecision::granted();
    }

    #[\Override]
    public function capacity(): ?int
    {
        return $this->capacity;
    }
}
