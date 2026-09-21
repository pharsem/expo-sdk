<?php

declare(strict_types=1);

namespace Expo\Push\Support;

/**
 * Spreads the retries of many workers over the backoff window.
 */
interface Jitter
{
    /**
     * @param int $delayMs the backoff that the policy computed
     *
     * @return int the delay to use, never below zero and never above $delayMs
     */
    public function apply(int $delayMs): int;
}
