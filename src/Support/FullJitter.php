<?php

declare(strict_types=1);

namespace Expo\Push\Support;

/**
 * Picks a random delay between zero and the computed backoff.
 *
 * This is the default. It stops many workers from retrying at the same moment.
 */
final readonly class FullJitter implements Jitter
{
    #[\Override]
    public function apply(int $delayMs): int
    {
        return $delayMs <= 0 ? 0 : random_int(0, $delayMs);
    }
}
