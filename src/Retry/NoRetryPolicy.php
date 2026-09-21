<?php

declare(strict_types=1);

namespace Expo\Push\Retry;

use Expo\Push\Result\OperationType;

/**
 * One attempt for each chunk, and no retry at all.
 *
 * Use it when a queue worker owns the retries. Read the result and schedule the
 * failed positions yourself. Read `SendResult::unknown()` first: those
 * notifications can already be on their way.
 */
final readonly class NoRetryPolicy implements RetryPolicy
{
    private RetrySettings $settings;

    public function __construct(?RetrySettings $settings = null)
    {
        $this->settings = ($settings ?? new RetrySettings())->withMaxAttempts(1);
    }

    #[\Override]
    public function settings(): RetrySettings
    {
        return $this->settings;
    }

    #[\Override]
    public function decide(OperationType $operation, int $attempt, AttemptOutcome $outcome): RetryDecision
    {
        return RetryDecision::stop('retries are off');
    }
}
