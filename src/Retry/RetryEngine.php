<?php

declare(strict_types=1);

namespace Expo\Push\Retry;

use Expo\Push\Support\Jitter;

/**
 * Turns a retry decision into a delay.
 *
 * One engine serves the sequential path and the concurrent path, so both count
 * the attempts the same way and wait the same time.
 */
final readonly class RetryEngine
{
    public function __construct(
        private RetryPolicy $policy,
        private Jitter $jitter,
    ) {
    }

    public function settings(): RetrySettings
    {
        return $this->policy->settings();
    }

    public function policy(): RetryPolicy
    {
        return $this->policy;
    }

    /**
     * The delay before attempt number `$next`, in milliseconds.
     *
     * The SDK takes the larger of two numbers: its own backoff with the jitter,
     * and the delay that the server asked for. A server that asks for 120 seconds
     * always gets 120 seconds, whatever the local cap says.
     *
     * @param int      $next          the number of the next attempt, from 2
     * @param int|null $serverDelayMs the `Retry-After` value in milliseconds
     */
    public function delayFor(int $next, ?int $serverDelayMs): int
    {
        $local = $this->jitter->apply($this->policy->settings()->backoffFor($next));

        if ($serverDelayMs === null) {
            return max(0, $local);
        }

        return max(0, $local, $serverDelayMs);
    }
}
