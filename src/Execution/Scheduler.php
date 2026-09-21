<?php

declare(strict_types=1);

namespace Expo\Push\Execution;

use Expo\Push\Observability\ChunkDeferred;
use Expo\Push\Observability\ChunkFinished;
use Expo\Push\Observability\ChunkStarted;
use Expo\Push\Observability\Event;
use Expo\Push\Observability\SafeObserver;
use Expo\Push\Observability\WaitReason;
use Expo\Push\Observability\WaitScheduled;
use Expo\Push\RateLimit\RateLimiter;
use Expo\Push\Result\AttemptRecord;
use Expo\Push\Result\FailureCategory;
use Expo\Push\Result\OperationType;
use Expo\Push\Observability\AttemptFinished;
use Expo\Push\Support\Clock;
use Expo\Push\Support\Redact;
use Expo\Push\Support\Sleeper;

/**
 * Runs the chunks of one operation, in order, with a bounded number in flight.
 *
 * The scheduler never dispatches every chunk at once. It activates a chunk only
 * when a slot is free, when the rate limiter grants the permits, when no
 * cooldown holds the bucket back and when no earlier failure told it to stop.
 *
 * A retry wait never blocks the chunks that are already in flight. The scheduler
 * polls the transport while another chunk waits for its backoff.
 *
 * One cooldown covers the whole bucket of the operation. A local limiter denial
 * and a server `Retry-After` both feed it, and the later of the two wins. A
 * chunk that finished never lets a fresh chunk step past a delay that the
 * project already owes. Buckets stay apart: one operation holds one bucket, so
 * a cooldown of one project never reaches another.
 *
 * Every wait obeys the operation deadline as well. The scheduler wakes at the
 * first of the retry time, the cooldown and the deadline, and it never sleeps
 * past the deadline to reach a retry that it may no longer start.
 *
 * After a chunk fails or defers, the scheduler stops activating new chunks. The
 * chunks that are already in flight finish their own work, and the result keeps
 * their answers. Set `continueAfterFailure` to activate the later chunks too.
 *
 * The scheduler never drops a request that is already on the wire to make a
 * result look clean: a cancelled request after transmission is ambiguous, and
 * ambiguity is what the SDK reports, not what it hides.
 */
final class Scheduler
{
    /**
     * The longest one poll waits when nothing else bounds it.
     */
    private const int MAX_POLL_MS = 1_000;

    /**
     * The monotonic moment until which the whole bucket waits, or null.
     */
    private ?int $cooldownUntil = null;

    /**
     * The same moment as `$cooldownUntil`, in UTC milliseconds.
     */
    private ?int $cooldownUntilUtc = null;

    /**
     * True when an earlier chunk told the scheduler to stop activating work.
     */
    private bool $stopped = false;

    /**
     * True when the failure that stopped the run can pass on a later attempt.
     */
    private bool $stopRetryable = true;

    public function __construct(
        private readonly Dispatcher $dispatcher,
        private readonly Clock $clock,
        private readonly Sleeper $sleeper,
        private readonly RateLimiter $limiter,
        private readonly string $bucket,
        private readonly SafeObserver $observer,
        private readonly string $operationId,
        private readonly OperationType $operation,
        private readonly bool $continueAfterFailure = false,
        private readonly ?int $operationDeadlineMs = null,
        private readonly int $maxInlineWaitMs = 10_000,
    ) {
    }

    /**
     * @param list<ChunkRunner> $chunks
     */
    public function run(array $chunks): void
    {
        if ($chunks === []) {
            return;
        }

        $byOrdinal = [];

        foreach ($chunks as $chunk) {
            $byOrdinal[$chunk->ordinal] = $chunk;
        }

        $start = $this->clock->monotonicMillis();
        $deadline = $this->operationDeadlineMs === null ? null : $start + $this->operationDeadlineMs;

        try {
            while (true) {
                // An expired deadline finalizes the work that has not started
                // and the work that waits, whatever its retry time says. The
                // requests that are already on the wire keep their answers.
                $this->expire($chunks, $deadline);
                $this->activate($chunks, $deadline);

                if ($this->dispatcher->inFlight() > 0) {
                    $this->collect($byOrdinal, $chunks, $deadline);

                    continue;
                }

                $next = $this->earliestWait($chunks, $deadline);

                if ($next === null) {
                    break;
                }

                $delay = $next - $this->clock->monotonicMillis();
                $this->sleeper->sleepMillis($delay > 0 ? $delay : 1);
            }
        } finally {
            $this->dispatcher->cancelAll();
        }
    }

    /**
     * Ends every chunk that the operation deadline caught.
     *
     * A chunk that never went out stays "not attempted". A chunk that already
     * sent a request keeps its attempts, its ambiguity and its retry time, so
     * the result never trades evidence for a clean ending.
     *
     * @param list<ChunkRunner> $chunks
     */
    private function expire(array $chunks, ?int $deadline): void
    {
        if ($deadline === null || $this->clock->monotonicMillis() < $deadline) {
            return;
        }

        foreach ($chunks as $chunk) {
            if ($chunk->isDone() || $chunk->state() === ChunkState::InFlight) {
                continue;
            }

            $chunk->skip(
                FailureCategory::Deadline,
                $chunk->wasDispatched()
                    ? 'the operation deadline ran out before this chunk could go out again'
                    : 'the operation deadline ran out before this chunk started',
                $this->cooldownUntilUtc ?? $this->clock->nowUtcMillis(),
            );
            $this->emitFinished($chunk);
            $this->stopped = true;
        }
    }

    /**
     * @param list<ChunkRunner> $chunks
     */
    private function activate(array $chunks, ?int $deadline): void
    {
        while (true) {
            $now = $this->clock->monotonicMillis();

            if ($this->heldSlots($chunks, $now) >= $this->dispatcher->maxInFlight()) {
                return;
            }

            $chunk = $this->nextReady($chunks, $now);

            if ($chunk === null) {
                return;
            }

            if ($this->stopped && !$this->continueAfterFailure && !$chunk->wasDispatched()) {
                // The chunk never went out, so it keeps whatever retry guidance
                // the operation already holds. A cooldown of the bucket still
                // applies to it.
                $chunk->skip(
                    FailureCategory::Skipped,
                    'an earlier request of this operation failed, so the SDK did not start this chunk',
                    $this->cooldownUntilUtc,
                    $this->stopRetryable,
                );
                $this->emitFinished($chunk);

                continue;
            }

            if ($this->cooldownUntil !== null && $this->cooldownUntil > $now) {
                // The whole bucket waits. Do not push another chunk of the same
                // project past a limit that just refused one.
                if (!$this->holdForCooldown($chunk, $this->cooldownUntil - $now)) {
                    continue;
                }

                return;
            }

            if (!$chunk->canDispatch($now, $this->remaining($deadline, $now))) {
                $this->failDeadline($chunk);

                continue;
            }

            $chunk->markActivated($now);

            try {
                $decision = $this->limiter->acquire($this->bucket, $chunk->size);
            } catch (\Exception $exception) {
                // A limiter that cannot answer must not become a silent send
                // without a limit. The SDK stops and keeps every result so far.
                $this->failLimiter($chunks, $exception);

                return;
            }

            // The limiter call can block, so the budgets need a second look.
            $now = $this->clock->monotonicMillis();

            if (!$decision->granted) {
                $wait = max(1, $decision->retryAfterMs);
                $this->extendCooldown($now + $wait, $this->clock->nowUtcMillis() + $wait);

                if ($chunk->waitForCooldown($wait, $now, sprintf('the rate limiter of bucket "%s"', $this->bucket))) {
                    $this->emit(new WaitScheduled(
                        $this->operationId,
                        $this->operation,
                        $this->clock->nowUtcMillis(),
                        $chunk->ordinal,
                        WaitReason::RateLimit,
                        $wait,
                        $chunk->attemptNumber() + 1,
                        sprintf('bucket %s', $this->bucket)
                    ));

                    return;
                }

                $this->emitFinished($chunk);
                $this->markStopped($chunk);

                continue;
            }

            if (!$chunk->canDispatch($now, $this->remaining($deadline, $now))) {
                $this->failDeadline($chunk);

                continue;
            }

            $request = $chunk->nextRequest($now, $this->remaining($deadline, $now));
            $this->dispatcher->start($chunk->ordinal, $request);

            $this->emit(new ChunkStarted(
                $this->operationId,
                $this->operation,
                $this->clock->nowUtcMillis(),
                $chunk->ordinal,
                $chunk->size,
                $chunk->attemptNumber(),
                $this->dispatcher->inFlight()
            ));
        }
    }

    /**
     * Holds one chunk back for the cooldown of the bucket.
     *
     * A cooldown that fits the inline allowance and the budget becomes a wait.
     * A longer one becomes a deferral that names the moment: the SDK never
     * shortens a delay that a limit asked for.
     *
     * @return bool true when the chunk now waits, false when it reached a final state
     */
    private function holdForCooldown(ChunkRunner $chunk, int $waitMs): bool
    {
        $source = sprintf('the cooldown of bucket "%s"', $this->bucket);

        if ($waitMs <= $this->maxInlineWaitMs
            && $chunk->waitForCooldown($waitMs, $this->clock->monotonicMillis(), $source)
        ) {
            $this->emit(new WaitScheduled(
                $this->operationId,
                $this->operation,
                $this->clock->nowUtcMillis(),
                $chunk->ordinal,
                WaitReason::RateLimit,
                $waitMs,
                $chunk->attemptNumber() + 1,
                sprintf('bucket %s cooldown', $this->bucket)
            ));

            return true;
        }

        // The cooldown outlives what the SDK waits inside one call. Finalize the
        // chunk with the moment, and let the application schedule it.
        $chunk->waitForCooldown($waitMs, $this->clock->monotonicMillis(), $source);
        $this->emitFinished($chunk);
        $this->markStopped($chunk);

        return false;
    }

    /**
     * @param array<int, ChunkRunner> $byOrdinal
     * @param list<ChunkRunner>       $chunks
     */
    private function collect(array $byOrdinal, array $chunks, ?int $deadline): void
    {
        $timeout = $this->pollTimeout($chunks, $deadline);

        foreach ($this->dispatcher->poll($timeout) as $completed) {
            $chunk = $byOrdinal[$completed->id] ?? null;

            if ($chunk === null) {
                continue;
            }

            $result = $completed->response ?? $completed->failure;

            if ($result === null) {
                continue;
            }

            $chunk->complete($result, $this->clock->monotonicMillis());
            $this->emitLastAttempt($chunk);

            // The server asked the project to wait, not only this chunk. Lift
            // the delay to the bucket before any other chunk starts.
            $this->extendCooldown($chunk->serverCooldownUntil(), $chunk->serverCooldownUntilUtc());

            if (!$chunk->isDone()) {
                $this->emit(new WaitScheduled(
                    $this->operationId,
                    $this->operation,
                    $this->clock->nowUtcMillis(),
                    $chunk->ordinal,
                    WaitReason::Retry,
                    max(0, ($chunk->readyAt() ?? 0) - $this->clock->monotonicMillis()),
                    $chunk->attemptNumber() + 1
                ));

                continue;
            }

            $this->emitFinished($chunk);

            if ($chunk->outcome()?->succeeded !== true) {
                $this->markStopped($chunk);
            }
        }
    }

    /**
     * Raises the shared cooldown to the later of the two moments.
     *
     * A shorter delay that arrives afterwards never releases the bucket early.
     */
    private function extendCooldown(?int $untilMonotonic, ?int $untilUtc): void
    {
        if ($untilMonotonic === null) {
            return;
        }

        if ($this->cooldownUntil !== null && $this->cooldownUntil >= $untilMonotonic) {
            return;
        }

        $this->cooldownUntil = $untilMonotonic;
        $this->cooldownUntilUtc = $untilUtc;
    }

    /**
     * Remembers that the run stopped, and whether a repeat can work.
     */
    private function markStopped(ChunkRunner $chunk): void
    {
        $this->stopped = true;

        // A chunk that cannot pass later must not make the chunks behind it
        // look retryable.
        if ($chunk->outcome()?->retryable !== true) {
            $this->stopRetryable = false;
        }
    }

    /**
     * What the operation deadline leaves, or null when there is no deadline.
     */
    private function remaining(?int $deadline, int $now): ?int
    {
        return $deadline === null ? null : $deadline - $now;
    }

    private function failDeadline(ChunkRunner $chunk): void
    {
        $chunk->skip(
            FailureCategory::Deadline,
            'the remaining budget of this chunk ran out before the request started',
            $this->cooldownUntilUtc ?? $this->clock->nowUtcMillis(),
        );
        $this->emitFinished($chunk);
        $this->stopped = true;
    }

    /**
     * The number of chunks that hold a slot.
     *
     * A chunk that waits for a backoff or for a permit keeps its slot. With a
     * concurrency of 1 the SDK therefore finishes one chunk before it starts the
     * next one, and a retry never lets a later chunk overtake an earlier one.
     *
     * @param list<ChunkRunner> $chunks
     */
    private function heldSlots(array $chunks, int $now): int
    {
        $waiting = 0;

        foreach ($chunks as $chunk) {
            if ($chunk->isWaiting() && !$chunk->isReady($now)) {
                ++$waiting;
            }
        }

        return $this->dispatcher->inFlight() + $waiting;
    }

    /**
     * @param list<ChunkRunner> $chunks
     */
    private function nextReady(array $chunks, int $now): ?ChunkRunner
    {
        foreach ($chunks as $chunk) {
            if (!$chunk->isDone() && $chunk->state() !== ChunkState::InFlight && $chunk->isReady($now)) {
                return $chunk;
            }
        }

        return null;
    }

    /**
     * The next monotonic moment at which something can change.
     *
     * The answer reads every constraint at once: the retry time of each open
     * chunk, the cooldown of the bucket and the operation deadline. A chunk that
     * waits for a slot rather than for time adds nothing, so the loop never
     * spins on it.
     *
     * @param list<ChunkRunner> $chunks
     */
    private function earliestWait(array $chunks, ?int $deadline): ?int
    {
        $now = $this->clock->monotonicMillis();
        $earliest = null;
        $open = false;

        foreach ($chunks as $chunk) {
            if ($chunk->isDone()) {
                continue;
            }

            $open = true;
            $readyAt = $chunk->readyAt();

            if ($readyAt === null) {
                continue;
            }

            // Every chunk of one operation shares the bucket, so a cooldown
            // holds all of them back, whatever their own backoff says.
            if ($this->cooldownUntil !== null && $this->cooldownUntil > $readyAt) {
                $readyAt = $this->cooldownUntil;
            }

            $earliest = $earliest === null ? $readyAt : min($earliest, $readyAt);
        }

        if (!$open) {
            return null;
        }

        if ($earliest === null && $this->cooldownUntil !== null && $this->cooldownUntil > $now) {
            // Nothing waits on a timer of its own, and the cooldown still holds
            // the pending chunks back.
            $earliest = $this->cooldownUntil;
        }

        if ($earliest === null) {
            // Activation already refused every chunk and no timer is running,
            // so nothing can change any more.
            return null;
        }

        // A deadline never lengthens a wait, and it never lets one run past it.
        return $deadline !== null && $deadline < $earliest ? $deadline : $earliest;
    }

    /**
     * @param list<ChunkRunner> $chunks
     */
    private function pollTimeout(array $chunks, ?int $deadline): int
    {
        $now = $this->clock->monotonicMillis();
        $timeout = self::MAX_POLL_MS;

        foreach ($chunks as $chunk) {
            $readyAt = $chunk->isDone() ? null : $chunk->readyAt();

            if ($readyAt !== null) {
                $timeout = min($timeout, max(0, $readyAt - $now));
            }
        }

        if ($this->cooldownUntil !== null) {
            $timeout = min($timeout, max(0, $this->cooldownUntil - $now));
        }

        if ($deadline !== null) {
            $timeout = min($timeout, max(0, $deadline - $now));
        }

        return max(1, $timeout);
    }

    /**
     * @param list<ChunkRunner> $chunks
     */
    private function failLimiter(array $chunks, \Exception $exception): void
    {
        $message = sprintf('the rate limiter failed: %s', Redact::exception($exception));

        foreach ($chunks as $chunk) {
            if ($chunk->isDone() || $chunk->state() === ChunkState::InFlight) {
                continue;
            }

            // A broken limiter is not a transient send failure. An application
            // has to fix it before any of this work goes out again.
            $chunk->skip(FailureCategory::Limiter, $message, $this->cooldownUntilUtc, false);
            $this->emitFinished($chunk);
        }

        $this->stopped = true;
        $this->stopRetryable = false;
    }

    private function emitLastAttempt(ChunkRunner $chunk): void
    {
        $attempts = $chunk->attempts();
        $last = $attempts === [] ? null : $attempts[count($attempts) - 1];

        if (!$last instanceof AttemptRecord) {
            return;
        }

        $this->emit(new AttemptFinished(
            $this->operationId,
            $this->operation,
            $this->clock->nowUtcMillis(),
            $chunk->ordinal,
            $last
        ));
    }

    private function emitFinished(ChunkRunner $chunk): void
    {
        $outcome = $chunk->outcome();

        if ($outcome === null) {
            return;
        }

        if ($outcome->deferred) {
            $this->emit(new ChunkDeferred(
                $this->operationId,
                $this->operation,
                $this->clock->nowUtcMillis(),
                $chunk->ordinal,
                $chunk->size,
                $outcome->earliestRetryAtUtcMs,
                $outcome->message
            ));
        }

        $this->emit(new ChunkFinished(
            $this->operationId,
            $this->operation,
            $this->clock->nowUtcMillis(),
            $chunk->ordinal,
            $chunk->size,
            count($outcome->attempts),
            $chunk->elapsedMs($this->clock->monotonicMillis()),
            $outcome->succeeded,
            $outcome->category
        ));
    }

    private function emit(Event $event): void
    {
        $this->observer->emit($event);
    }
}
