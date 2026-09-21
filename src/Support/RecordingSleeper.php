<?php

declare(strict_types=1);

namespace Expo\Push\Support;

/**
 * A sleeper that records every wait and moves a frozen clock instead of blocking.
 *
 * Use it with `FrozenClock` to test the retry timing without a real delay.
 */
final class RecordingSleeper implements Sleeper
{
    /** @var list<int> */
    public array $waits = [];

    public function __construct(private ?FrozenClock $clock = null)
    {
    }

    #[\Override]
    public function sleepMillis(int $millis): void
    {
        if ($millis <= 0) {
            return;
        }

        $this->waits[] = $millis;
        $this->clock?->advance($millis);
    }

    public function totalMillis(): int
    {
        return array_sum($this->waits);
    }
}
