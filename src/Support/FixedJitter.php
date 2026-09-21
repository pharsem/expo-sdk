<?php

declare(strict_types=1);

namespace Expo\Push\Support;

/**
 * Returns the same share of the backoff every time. Use it in a test.
 *
 * `new FixedJitter(1.0)` keeps the full backoff. `new FixedJitter(0.0)` removes it.
 */
final readonly class FixedJitter implements Jitter
{
    public function __construct(private float $factor = 1.0)
    {
    }

    #[\Override]
    public function apply(int $delayMs): int
    {
        if ($delayMs <= 0) {
            return 0;
        }

        $factor = max(0.0, min(1.0, $this->factor));

        return (int) round($delayMs * $factor);
    }
}
