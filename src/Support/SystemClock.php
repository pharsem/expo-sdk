<?php

declare(strict_types=1);

namespace Expo\Push\Support;

/**
 * The clock of the machine. The SDK uses it when you inject nothing else.
 */
final readonly class SystemClock implements Clock
{
    #[\Override]
    public function nowUtcMillis(): int
    {
        return (int) round(microtime(true) * 1000);
    }

    #[\Override]
    public function monotonicMillis(): int
    {
        return intdiv(hrtime(true), 1_000_000);
    }
}
