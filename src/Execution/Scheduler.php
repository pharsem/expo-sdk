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
 * when a slot is free, when the rate limiter grants the permits and when no
 * earlier failure told it to stop.
 *
 * A retry wait never blocks the chunks that are already in flight. The scheduler
 * polls the transport while another chunk waits for its backoff.
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
        $stopped = false;
        $cooldownUntil = null;

        try {
            while (true) {
                $this->activate($chunks, $stopped, $cooldownUntil, $deadline);

                if ($this->dispatcher->inFlight() > 0) {
                    $this->collect($byOrdinal, $chunks, $stopped, $cooldownUntil, $deadline);

                    continue;
                }

                $next = $this->earliestWait($chunks, $cooldownUntil);

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
     * @param list<ChunkRunner> $chunks
     */
    private function activate(array $chunks, bool &$stopped, ?int &$cooldownUntil, ?int $deadline): void
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

            if ($stopped && !$this->continueAfterFailure && !$chunk->wasDispatched()) {
                $chunk->skip(
                    FailureCategory::Skipped,
                    'an earlier request of this operation failed, so the SDK did not start this chunk'
                );
                $this->emitFinished($chunk);

                continue;
            }

            if ($deadline !== null && $now >= $deadline) {
                $chunk->skip(
                    FailureCategory::Deadline,
                    'the operation deadline ran out before this chunk started',
                    $this->clock->nowUtcMillis()
                );
                $this->emitFinished($chunk);
                $stopped = true;

                continue;
            }

            if ($cooldownUntil !== null && $cooldownUntil > $now) {
                // The whole bucket waits. Do not push another chunk of the same
                // project past a limit that just refused one.
                return;
            }

            $chunk->markActivated($now);

            try {
                $decision = $this->limiter->acquire($this->bucket, $chunk->size);
            } catch (\Exception $exception) {
                // A limiter that cannot answer must not become a silent send
                // without a limit. The SDK stops and keeps every result so far.
                $this->failLimiter($chunks, $exception);
                $stopped = true;

                return;
            }

            if (!$decision->granted) {
                $wait = max(1, $decision->retryAfterMs);
                $cooldownUntil = $now + $wait;

                if ($chunk->waitForPermit($wait, $now, $this->bucket)) {
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

                // The chunk gave up on the wait, so nothing waits for this
                // cooldown any more. Keeping it would make the loop sleep the
                // whole limiter delay before it marks the later chunks skipped,
                // and that defeats the inline wait cap.
                $cooldownUntil = null;
                $this->emitFinished($chunk);
                $stopped = true;

                continue;
            }

            $request = $chunk->nextRequest($now, $deadline === null ? null : $deadline - $now);
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
     * @param array<int, ChunkRunner> $byOrdinal
     * @param list<ChunkRunner>       $chunks
     */
    private function collect(
        array $byOrdinal,
        array $chunks,
        bool &$stopped,
        ?int &$cooldownUntil,
        ?int $deadline,
    ): void {
        $timeout = $this->pollTimeout($chunks, $cooldownUntil, $deadline);

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
                $stopped = true;
            }
        }
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
     * @param list<ChunkRunner> $chunks
     */
    private function earliestWait(array $chunks, ?int $cooldownUntil): ?int
    {
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

            $earliest = $earliest === null ? $readyAt : min($earliest, $readyAt);
        }

        if (!$open) {
            return null;
        }

        if ($cooldownUntil === null) {
            // A pending chunk with no wait and no cooldown means that activation
            // already refused every chunk, so nothing can change any more.
            return $earliest;
        }

        // Every chunk of one operation shares the bucket, so a cooldown holds
        // all of them back, whatever their own backoff says.
        return $earliest === null ? $cooldownUntil : max($earliest, $cooldownUntil);
    }

    /**
     * @param list<ChunkRunner> $chunks
     */
    private function pollTimeout(array $chunks, ?int $cooldownUntil, ?int $deadline): int
    {
        $now = $this->clock->monotonicMillis();
        $timeout = self::MAX_POLL_MS;

        foreach ($chunks as $chunk) {
            $readyAt = $chunk->isDone() ? null : $chunk->readyAt();

            if ($readyAt !== null) {
                $timeout = min($timeout, max(0, $readyAt - $now));
            }
        }

        if ($cooldownUntil !== null) {
            $timeout = min($timeout, max(0, $cooldownUntil - $now));
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

            $chunk->skip(FailureCategory::Limiter, $message);
            $this->emitFinished($chunk);
        }
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
