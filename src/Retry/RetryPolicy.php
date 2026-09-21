<?php

declare(strict_types=1);

namespace Expo\Push\Retry;

use Expo\Push\Result\OperationType;

/**
 * Decides whether the SDK tries one chunk again.
 *
 * One engine runs every retry, for the sequential path and for the concurrent
 * path. A transport must never repeat a request behind this policy.
 */
interface RetryPolicy
{
    /**
     * The numbers that bound the attempts, the waits and the budget.
     */
    public function settings(): RetrySettings;

    /**
     * @param OperationType $operation which endpoint the chunk talks to
     * @param int           $attempt   the number of the attempt that just finished, from 1
     */
    public function decide(OperationType $operation, int $attempt, AttemptOutcome $outcome): RetryDecision;
}
