<?php

declare(strict_types=1);

namespace Expo\Push\Tests\Support;

use Expo\Push\RateLimit\PermitDecision;
use Expo\Push\RateLimit\RateLimiter;
use RuntimeException;

/**
 * A limiter that records every call and answers from a script.
 */
final class RecordingLimiter implements RateLimiter
{
    /** @var list<array{bucket: string, permits: int}> */
    public array $calls = [];

    /** @var list<PermitDecision> */
    private array $answers = [];

    public function __construct(
        private readonly ?int $capacity = null,
        private readonly bool $failAfterFirst = false,
    ) {
    }

    public function deny(int $retryAfterMs): self
    {
        $this->answers[] = PermitDecision::wait($retryAfterMs);

        return $this;
    }

    public function grant(): self
    {
        $this->answers[] = PermitDecision::granted();

        return $this;
    }

    #[\Override]
    public function acquire(string $bucket, int $permits): PermitDecision
    {
        $this->calls[] = ['bucket' => $bucket, 'permits' => $permits];

        if ($this->failAfterFirst && count($this->calls) > 1) {
            throw new RuntimeException('the shared limiter is unreachable');
        }

        return array_shift($this->answers) ?? PermitDecision::granted();
    }

    #[\Override]
    public function capacity(): ?int
    {
        return $this->capacity;
    }

    /**
     * @return list<int>
     */
    public function permits(): array
    {
        return array_map(static fn (array $call): int => $call['permits'], $this->calls);
    }

    /**
     * @return list<string>
     */
    public function buckets(): array
    {
        return array_map(static fn (array $call): string => $call['bucket'], $this->calls);
    }
}
