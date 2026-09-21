<?php

declare(strict_types=1);

namespace Expo\Push\Support;

/**
 * Waits for a number of milliseconds.
 *
 * The SDK never sleeps inside a transport or inside a rate limiter. Every wait
 * goes through this interface, so a test can replace it.
 */
interface Sleeper
{
    public function sleepMillis(int $millis): void;
}
