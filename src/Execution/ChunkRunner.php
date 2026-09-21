<?php

declare(strict_types=1);

namespace Expo\Push\Execution;

use Closure;
use Expo\Push\Http\HttpRequest;
use Expo\Push\Http\HttpResponse;
use Expo\Push\Http\Transmission;
use Expo\Push\Http\TransportFailure;
use Expo\Push\Protocol\ExpoApiError;
use Expo\Push\Protocol\ParsedResponse;
use Expo\Push\Protocol\ProtocolFailureKind;
use Expo\Push\Protocol\SendResponseParser;
use Expo\Push\Result\AttemptRecord;
use Expo\Push\Result\FailureCategory;
use Expo\Push\Result\OperationType;
use Expo\Push\Retry\AttemptOutcome;
use Expo\Push\Retry\AttemptResult;
use Expo\Push\Retry\DeliveryRetryPolicy;
use Expo\Push\Retry\RetryAfter;
use Expo\Push\Retry\RetryEngine;
use Expo\Push\Support\Clock;
use Expo\Push\Support\Redact;

/**
 * One chunk, from the first attempt to a final state.
 *
 * The runner owns the attempts, the classification, the retry decision and the
 * budget of one chunk. The scheduler owns only the dispatch order, so the
 * sequential path and the concurrent path behave the same.
 */
final class ChunkRunner
{
    private ChunkState $state = ChunkState::Pending;

    private int $attempt = 0;

    /** @var list<AttemptRecord> */
    private array $attempts = [];

    /** @var list<string> */
    private array $warnings = [];

    private ?int $activatedAtMonotonic = null;

    private ?int $waitUntilMonotonic = null;

    /**
     * The same moment as `$waitUntilMonotonic`, in UTC milliseconds.
     *
     * A monotonic value means nothing to another process, so a chunk that ends
     * while it waits reports this one instead.
     */
    private ?int $waitUntilUtc = null;

    private ?int $serverCooldownUntilMonotonic = null;

    private ?int $serverCooldownUntilUtc = null;

    private ?int $lastStatus = null;

    /** @var list<ExpoApiError> */
    private array $lastApiErrors = [];

    private ?int $attemptStartedMonotonic = null;

    private ?int $attemptStartedUtc = null;

    private ?ChunkOutcome $outcome = null;

    private bool $dispatched = false;

    private bool $anyAmbiguous = false;

    private bool $serverRefused = false;

    private ?ParsedResponse $lastParsed = null;

    /**
     * @param int      $ordinal the position of the chunk in the operation
     * @param int      $size    the number of notifications or receipt IDs
     * @param Closure(HttpResponse): ParsedResponse $parser reads a 2xx body
     */
    public function __construct(
        public readonly int $ordinal,
        public readonly int $size,
        private readonly HttpRequest $request,
        private readonly RetryEngine $engine,
        private readonly OperationType $operation,
        private readonly Clock $clock,
        private readonly Closure $parser,
    ) {
    }

    public function state(): ChunkState
    {
        return $this->state;
    }

    public function isDone(): bool
    {
        return $this->state === ChunkState::Done;
    }

    public function isPending(): bool
    {
        return $this->state === ChunkState::Pending;
    }

    public function isWaiting(): bool
    {
        return $this->state === ChunkState::Waiting;
    }

    public function wasDispatched(): bool
    {
        return $this->dispatched;
    }

    public function attemptNumber(): int
    {
        return $this->attempt;
    }

    /**
     * Every attempt so far, oldest first.
     *
     * @return list<AttemptRecord>
     */
    public function attempts(): array
    {
        return $this->attempts;
    }

    /**
     * The milliseconds since the chunk became active, limiter waits included.
     */
    public function elapsedMs(int $nowMonotonic): int
    {
        if ($this->activatedAtMonotonic === null) {
            return 0;
        }

        return max(0, $nowMonotonic - $this->activatedAtMonotonic);
    }

    /**
     * The monotonic time at which this chunk may go out again, or null.
     */
    public function readyAt(): ?int
    {
        return $this->waitUntilMonotonic;
    }

    public function outcome(): ?ChunkOutcome
    {
        return $this->outcome;
    }

    public function isReady(int $nowMonotonic): bool
    {
        if ($this->state === ChunkState::Pending) {
            return true;
        }

        return $this->state === ChunkState::Waiting
            && $this->waitUntilMonotonic !== null
            && $this->waitUntilMonotonic <= $nowMonotonic;
    }

    /**
     * Starts the budget clock. The budget covers the limiter waits as well.
     */
    public function markActivated(int $nowMonotonic): void
    {
        $this->activatedAtMonotonic ??= $nowMonotonic;
    }

    /**
     * The milliseconds that this chunk has left of its budget.
     */
    public function remainingBudgetMs(int $nowMonotonic): int
    {
        $budget = $this->engine->settings()->chunkBudgetMs;

        if ($this->activatedAtMonotonic === null) {
            return $budget;
        }

        return $budget - ($nowMonotonic - $this->activatedAtMonotonic);
    }

    /**
     * The milliseconds that the strictest enforceable budget leaves.
     *
     * The value covers the chunk budget and the operation deadline together. A
     * value of zero or less means that no request may start.
     *
     * @param int|null $remainingOperationMs what the whole operation deadline leaves, or null
     */
    public function remainingEnforceableMs(int $nowMonotonic, ?int $remainingOperationMs = null): int
    {
        $remaining = $this->remainingBudgetMs($nowMonotonic);

        if ($remainingOperationMs !== null) {
            $remaining = min($remaining, $remainingOperationMs);
        }

        return $remaining;
    }

    /**
     * True when a request of this chunk may still start.
     *
     * The scheduler asks before every permit and again before every dispatch. A
     * budget that ran out never becomes an unbounded request.
     *
     * @param int|null $remainingOperationMs what the whole operation deadline leaves, or null
     */
    public function canDispatch(int $nowMonotonic, ?int $remainingOperationMs = null): bool
    {
        // The budget starts at the first activation. A chunk that never became
        // active holds its whole budget, so only the operation deadline can
        // stop it here.
        return $this->remainingEnforceableMs($nowMonotonic, $remainingOperationMs) > 0;
    }

    /**
     * Builds the request of the next attempt and marks the chunk in flight.
     *
     * The timeout of the request never outlives the strictest remaining budget.
     * An exhausted budget is not a licence for an unbounded request: the
     * scheduler asks `canDispatch()` first, and this method clamps whatever is
     * left to at least one millisecond.
     *
     * @param int|null $remainingOperationMs what the whole operation deadline leaves, or null
     */
    public function nextRequest(int $nowMonotonic, ?int $remainingOperationMs = null): HttpRequest
    {
        $this->markActivated($nowMonotonic);

        ++$this->attempt;
        $this->state = ChunkState::InFlight;
        $this->waitUntilMonotonic = null;
        $this->waitUntilUtc = null;
        $this->dispatched = true;
        $this->attemptStartedMonotonic = $nowMonotonic;
        $this->attemptStartedUtc = $this->clock->nowUtcMillis();

        $limit = $this->remainingEnforceableMs($nowMonotonic, $remainingOperationMs);
        $timeout = min($this->request->timeoutMs, max(1, $limit));

        return $timeout === $this->request->timeoutMs ? $this->request : $this->request->withTimeoutMs($timeout);
    }

    /**
     * Reads one finished attempt and decides what happens next.
     */
    public function complete(HttpResponse|TransportFailure $result, int $nowMonotonic): void
    {
        $nowUtc = $this->clock->nowUtcMillis();
        $duration = $nowMonotonic - ($this->attemptStartedMonotonic ?? $nowMonotonic);

        [$outcome, $parsed, $apiErrors] = $this->classify($result, $nowUtc, $duration);

        if ($outcome->isAmbiguous()) {
            $this->anyAmbiguous = true;
        }

        $this->serverRefused = $outcome->serverRefused();
        $this->lastStatus = $outcome->status;
        $this->lastApiErrors = $apiErrors;

        // The server spoke for the whole project, not for this chunk alone. The
        // cooldown outlives the attempt so that the scheduler can hold the other
        // chunks of the same bucket back as well.
        if ($outcome->serverDelayMs !== null) {
            $this->serverCooldownUntilMonotonic = self::later(
                $this->serverCooldownUntilMonotonic,
                $nowMonotonic + $outcome->serverDelayMs
            );
            $this->serverCooldownUntilUtc = self::later(
                $this->serverCooldownUntilUtc,
                $nowUtc + $outcome->serverDelayMs
            );
        }

        $this->attempts[] = new AttemptRecord(
            number: $this->attempt,
            result: $outcome->result,
            status: $outcome->status,
            code: $outcome->code(),
            transmission: $outcome->transmission,
            durationMs: $duration,
            startedAtUtcMs: $this->attemptStartedUtc,
            summary: self::summaryOf($outcome),
            serverDelayMs: $outcome->serverDelayMs,
        );

        if ($parsed !== null) {
            $this->lastParsed = $parsed;

            foreach ($parsed->notes() as $note) {
                $this->warnings[] = sprintf('chunk %d: %s', $this->ordinal, $note);
            }
        }

        if ($outcome->isSuccess() && $parsed !== null) {
            $this->finishSuccess($parsed);

            return;
        }

        $decision = $this->engine->policy()->decide($this->operation, $this->attempt, $outcome);

        if (!$decision->retry) {
            $this->finishFailure($outcome, $apiErrors, $decision->reason, $nowUtc);

            return;
        }

        $delay = $this->engine->delayFor($this->attempt + 1, $outcome->serverDelayMs);
        $settings = $this->engine->settings();
        $remaining = $this->remainingBudgetMs($nowMonotonic);

        if ($delay > $settings->maxInlineWaitMs) {
            $this->finishDeferred(
                $outcome,
                $apiErrors,
                sprintf(
                    'the next attempt is %d ms away and the SDK waits at most %d ms inside one call',
                    $delay,
                    $settings->maxInlineWaitMs
                ),
                $nowUtc + $delay,
                null
            );

            return;
        }

        if ($delay >= $remaining) {
            $this->finishDeferred(
                $outcome,
                $apiErrors,
                sprintf(
                    'the chunk budget of %d ms has %d ms left and the next attempt is %d ms away',
                    $settings->chunkBudgetMs,
                    max(0, $remaining),
                    $delay
                ),
                $nowUtc + $delay,
                FailureCategory::Deadline
            );

            return;
        }

        $this->state = ChunkState::Waiting;
        $this->waitUntilMonotonic = $nowMonotonic + $delay;
        $this->waitUntilUtc = $nowUtc + $delay;
    }

    /**
     * The delay that a rate limit asked for, local or from the server.
     *
     * The chunk waits when the delay fits the budget, and it defers when it does
     * not. Neither the limiter nor the server sleeps: the SDK schedules the wait.
     *
     * The SDK never shortens the delay to fit a local cap. A delay that does not
     * fit becomes a deferral that names the moment.
     *
     * @param string $source a redacted phrase that names who asked for the wait
     *
     * @return bool true when the chunk waits, false when it reached a final state
     */
    public function waitForCooldown(int $waitMs, int $nowMonotonic, string $source): bool
    {
        $this->markActivated($nowMonotonic);

        $settings = $this->engine->settings();
        $remaining = $this->remainingBudgetMs($nowMonotonic);
        $nowUtc = $this->clock->nowUtcMillis();

        if ($waitMs > $settings->maxInlineWaitMs || $waitMs >= $remaining) {
            $this->outcome = new ChunkOutcome(
                succeeded: false,
                response: $this->lastParsed,
                category: FailureCategory::RateLimited,
                message: sprintf('%s asks for %d ms, and the chunk cannot wait that long', $source, $waitMs),
                httpStatus: $this->lastStatus,
                expoErrors: $this->lastApiErrors,
                attempts: $this->attempts,
                retryable: true,
                deferred: true,
                earliestRetryAtUtcMs: $nowUtc + $waitMs,
                anyAmbiguousAttempt: $this->anyAmbiguous,
                serverRefused: $this->serverRefused,
                dispatched: $this->dispatched,
                warnings: $this->warnings,
            );
            $this->state = ChunkState::Done;

            return false;
        }

        $this->state = ChunkState::Waiting;
        $this->waitUntilMonotonic = $nowMonotonic + max(1, $waitMs);
        $this->waitUntilUtc = $nowUtc + max(1, $waitMs);

        return true;
    }

    /**
     * Ends the chunk without a request of its own.
     *
     * The scheduler calls this when an earlier chunk failed, when the operation
     * deadline ran out, or when the rate limiter itself failed.
     *
     * The chunk keeps whatever retry guidance it already holds. A chunk that
     * waits for a backoff and then meets the operation deadline still reports
     * the moment at which that backoff ends.
     *
     * @param bool $retryable false when the reason behind the skip needs an
     *                        application decision, such as a credential failure
     *                        or a limiter that broke
     */
    public function skip(
        FailureCategory $category,
        string $message,
        ?int $earliestRetryAtUtcMs = null,
        bool $retryable = true,
    ): void {
        if ($this->state === ChunkState::Done) {
            return;
        }

        $this->outcome = new ChunkOutcome(
            succeeded: false,
            response: $this->lastParsed,
            category: $category,
            message: $message,
            httpStatus: $this->lastStatus,
            expoErrors: $this->lastApiErrors,
            attempts: $this->attempts,
            retryable: $retryable,
            deferred: true,
            earliestRetryAtUtcMs: self::later($earliestRetryAtUtcMs, $this->waitUntilUtc),
            anyAmbiguousAttempt: $this->anyAmbiguous,
            serverRefused: $this->serverRefused,
            dispatched: $this->dispatched,
            warnings: $this->warnings,
        );
        $this->state = ChunkState::Done;
    }

    /**
     * The monotonic moment until which the server asked this project to wait.
     *
     * The value comes from the `Retry-After` header of the last answer that
     * carried one. It outlives the attempt, so the scheduler can hold the other
     * chunks of the same bucket back as well.
     */
    public function serverCooldownUntil(): ?int
    {
        return $this->serverCooldownUntilMonotonic;
    }

    /**
     * The same moment as `serverCooldownUntil()`, in UTC milliseconds.
     */
    public function serverCooldownUntilUtc(): ?int
    {
        return $this->serverCooldownUntilUtc;
    }

    /**
     * The largest of two moments, when at least one of them exists.
     */
    private static function later(?int $first, ?int $second): ?int
    {
        if ($first === null) {
            return $second;
        }

        if ($second === null) {
            return $first;
        }

        return max($first, $second);
    }

    /**
     * @return array{0: AttemptOutcome, 1: ParsedResponse|null, 2: list<ExpoApiError>}
     */
    private function classify(HttpResponse|TransportFailure $result, int $nowUtc, int $duration): array
    {
        if ($result instanceof TransportFailure) {
            return [
                new AttemptOutcome(
                    result: AttemptResult::TransportFailure,
                    transport: $result,
                    transmission: $result->transmission(),
                    durationMs: $duration,
                ),
                null,
                [],
            ];
        }

        $serverDelay = RetryAfter::fromResponse($result, $nowUtc);

        if (!$result->isSuccessful()) {
            // The status alone decides whether a retry makes sense. A 503 with an
            // HTML body still retries, because the SDK never needs a valid Expo
            // body to classify an HTTP failure.
            return [
                new AttemptOutcome(
                    result: AttemptResult::HttpFailure,
                    status: $result->status,
                    serverDelayMs: $serverDelay,
                    transmission: Transmission::Transmitted,
                    durationMs: $result->durationMs ?? $duration,
                ),
                null,
                SendResponseParser::errorsOnly($result->body),
            ];
        }

        $parsed = ($this->parser)($result);
        $failure = $parsed->protocolFailure();

        return [
            new AttemptOutcome(
                result: $failure === null ? AttemptResult::Success : AttemptResult::ProtocolFailure,
                status: $result->status,
                protocol: $failure,
                serverDelayMs: $serverDelay,
                transmission: Transmission::Transmitted,
                durationMs: $result->durationMs ?? $duration,
            ),
            $parsed,
            $parsed->errors(),
        ];
    }

    private function finishSuccess(ParsedResponse $parsed): void
    {
        $this->outcome = new ChunkOutcome(
            succeeded: true,
            response: $parsed,
            expoErrors: $parsed->errors(),
            attempts: $this->attempts,
            anyAmbiguousAttempt: $this->anyAmbiguous,
            serverRefused: false,
            dispatched: true,
            warnings: $this->warnings,
        );
        $this->state = ChunkState::Done;
    }

    /**
     * @param list<ExpoApiError> $apiErrors
     */
    private function finishFailure(AttemptOutcome $outcome, array $apiErrors, string $reason, int $nowUtc): void
    {
        // The flag says whether the failure itself can pass on a later attempt.
        // It follows the default delivery rules, not the policy that you chose,
        // so an application with retries off still learns what is transient.
        $fresh = self::classifier()->decide($this->operation, 0, $outcome);
        $serverDelay = $outcome->serverDelayMs;

        $this->outcome = new ChunkOutcome(
            succeeded: false,
            response: $this->lastParsed,
            category: self::categoryFor($outcome, $apiErrors),
            message: sprintf('%s after %d attempt(s): %s', self::summaryOf($outcome), $this->attempt, $reason),
            httpStatus: $outcome->status,
            transportCode: self::codeOf($outcome),
            expoErrors: $apiErrors,
            attempts: $this->attempts,
            retryable: $fresh->retry,
            deferred: false,
            earliestRetryAtUtcMs: $serverDelay === null ? null : $nowUtc + $serverDelay,
            anyAmbiguousAttempt: $this->anyAmbiguous,
            serverRefused: $this->serverRefused,
            dispatched: $this->dispatched,
            warnings: $this->warnings,
        );
        $this->state = ChunkState::Done;
    }

    /**
     * @param list<ExpoApiError> $apiErrors
     */
    private function finishDeferred(
        AttemptOutcome $outcome,
        array $apiErrors,
        string $reason,
        int $earliestRetryAtUtcMs,
        ?FailureCategory $category,
    ): void {
        $this->outcome = new ChunkOutcome(
            succeeded: false,
            response: $this->lastParsed,
            category: $category ?? self::categoryFor($outcome, $apiErrors),
            message: sprintf('%s, deferred: %s', self::summaryOf($outcome), $reason),
            httpStatus: $outcome->status,
            transportCode: self::codeOf($outcome),
            expoErrors: $apiErrors,
            attempts: $this->attempts,
            retryable: true,
            deferred: true,
            earliestRetryAtUtcMs: $earliestRetryAtUtcMs,
            anyAmbiguousAttempt: $this->anyAmbiguous,
            serverRefused: $this->serverRefused,
            dispatched: $this->dispatched,
            warnings: $this->warnings,
        );
        $this->state = ChunkState::Done;
    }

    /**
     * @param list<ExpoApiError> $apiErrors
     */
    private static function categoryFor(AttemptOutcome $outcome, array $apiErrors): FailureCategory
    {
        if ($outcome->result === AttemptResult::TransportFailure) {
            return FailureCategory::Transport;
        }

        if ($outcome->result === AttemptResult::ProtocolFailure) {
            return $outcome->protocol?->kind === ProtocolFailureKind::ErrorsWithoutData
                ? FailureCategory::Api
                : FailureCategory::Protocol;
        }

        if ($outcome->status === 429) {
            return FailureCategory::RateLimited;
        }

        foreach ($apiErrors as $error) {
            if ($error->code === 'TOO_MANY_REQUESTS') {
                return FailureCategory::RateLimited;
            }
        }

        return $apiErrors === [] ? FailureCategory::Http : FailureCategory::Api;
    }

    /**
     * The fixed rules that judge whether a failure can pass later.
     */
    private static function classifier(): DeliveryRetryPolicy
    {
        static $policy = null;

        return $policy ??= new DeliveryRetryPolicy();
    }

    /**
     * The transport error name, or the protocol failure kind.
     */
    private static function codeOf(AttemptOutcome $outcome): ?string
    {
        $code = $outcome->transport?->code;

        return $code ?? $outcome->protocol?->kind->value;
    }

    private static function summaryOf(AttemptOutcome $outcome): string
    {
        return match ($outcome->result) {
            AttemptResult::Success => sprintf('status %d', $outcome->status ?? 200),
            AttemptResult::HttpFailure => sprintf('status %d', $outcome->status ?? 0),
            AttemptResult::ProtocolFailure => Redact::text($outcome->protocol?->summary()) ?? 'protocol failure',
            AttemptResult::TransportFailure => Redact::text($outcome->transport?->summary()) ?? 'transport failure',
        };
    }
}
