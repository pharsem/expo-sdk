<?php

declare(strict_types=1);

namespace Expo\Push\Support;

/**
 * A clock that moves only when you tell it to. Use it in a test.
 *
 * ```php
 * $clock = new FrozenClock(utcMillis: 1_700_000_000_000);
 * $expo = new Expo(clock: $clock, sleeper: new RecordingSleeper($clock));
 * ```
 */
final class FrozenClock implements Clock
{
    public function __construct(
        private int $utcMillis = 1_700_000_000_000,
        private int $monotonicMillis = 0,
    ) {
    }

    #[\Override]
    public function nowUtcMillis(): int
    {
        return $this->utcMillis;
    }

    #[\Override]
    public function monotonicMillis(): int
    {
        return $this->monotonicMillis;
    }

    /**
     * Moves both clocks forward by the same number of milliseconds.
     */
    public function advance(int $millis): void
    {
        $this->utcMillis += $millis;
        $this->monotonicMillis += $millis;
    }
}
