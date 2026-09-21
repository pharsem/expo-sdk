<?php

declare(strict_types=1);

namespace Expo\Push\Support;

/**
 * The real sleeper. It blocks the process.
 */
final readonly class SystemSleeper implements Sleeper
{
    #[\Override]
    public function sleepMillis(int $millis): void
    {
        if ($millis > 0) {
            usleep($millis * 1000);
        }
    }
}
