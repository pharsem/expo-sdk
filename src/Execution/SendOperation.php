<?php

declare(strict_types=1);

namespace Expo\Push\Execution;

use Expo\Push\Http\HttpResponse;
use Expo\Push\Http\RequestFactory;
use Expo\Push\Observability\OperationFinished;
use Expo\Push\Observability\OperationStarted;
use Expo\Push\Observability\SafeObserver;
use Expo\Push\Plan\SendChunk;
use Expo\Push\Plan\SendPlan;
use Expo\Push\Protocol\SendResponse;
use Expo\Push\Protocol\SendResponseParser;
use Expo\Push\PushTicket;
use Expo\Push\RateLimit\RateLimiter;
use Expo\Push\Result\Acceptance;
use Expo\Push\Result\NotAcceptedReason;
use Expo\Push\Result\NotificationOutcome;
use Expo\Push\Result\OperationType;
use Expo\Push\Result\RecoveryDisposition;
use Expo\Push\Result\RequestFailure;
use Expo\Push\Result\SendResult;
use Expo\Push\Retry\RetryEngine;
use Expo\Push\Support\Clock;
use Expo\Push\Support\Sleeper;

/**
 * Runs one send operation and turns the chunk outcomes into a `SendResult`.
 *
 * The rules for the acceptance of one notification:
 *
 * - A valid ticket with the status `ok` gives `Accepted`.
 * - A valid ticket with the status `error` gives `NotAccepted`, unless an
 *   earlier attempt was ambiguous. The last answer cannot say what the earlier
 *   attempt did, so the result stays `Unknown`.
 * - A malformed entry at a trustworthy position gives `Unknown`. Every unknown
 *   acceptance of a dispatched chunk carries the duplicate risk.
 * - A failed request gives `NotAccepted` when the SDK knows that Expo accepted
 *   nothing: every attempt failed before transmission, or the server refused the
 *   request with a 4xx status. It gives `Unknown` otherwise.
 * - A chunk that never went out gives `NotAttempted`.
 *
 * The recovery disposition follows a second, separate rule. Acceptance says what
 * Expo did. Recovery says what is left to do:
 *
 * - An accepted notification is never open work.
 * - A ticket rejection closes the work only when the error code says that a
 *   later send cannot work. `MessageRateExceeded` stays open, and an error code
 *   that the SDK does not know asks the application to look.
 * - A failed or skipped chunk gives its own retryability to every notification
 *   that it held, and to those alone. A transient failure of one chunk never
 *   makes the permanent rejection of another chunk look retryable.
 */
final readonly class SendOperation
{
    /**
     * @param list<SendChunk> $chunks
     */
    public function __construct(
        private SendPlan $plan,
        private array $chunks,
        private RequestFactory $requests,
        private RetryEngine $engine,
        private Dispatcher $dispatcher,
        private Clock $clock,
        private Sleeper $sleeper,
        private RateLimiter $limiter,
        private string $bucket,
        private SafeObserver $observer,
        private string $operationId,
        private bool $continueAfterFailure,
        private ?int $operationDeadlineMs,
    ) {
    }

    public function run(): SendResult
    {
        if ($this->chunks === []) {
            return new SendResult();
        }

        $startedAt = $this->clock->monotonicMillis();
        $runners = [];

        foreach ($this->chunks as $chunk) {
            $tokens = $chunk->tokens;

            $runners[] = new ChunkRunner(
                ordinal: $chunk->ordinal,
                size: $chunk->count(),
                request: $this->requests->send($chunk->body),
                engine: $this->engine,
                operation: OperationType::Send,
                clock: $this->clock,
                parser: static fn (HttpResponse $response): SendResponse
                    => SendResponseParser::parse($response->body, $tokens),
            );
        }

        $this->observer->emit(new OperationStarted(
            $this->operationId,
            OperationType::Send,
            $this->clock->nowUtcMillis(),
            $this->plan->count(),
            count($this->chunks),
            $this->dispatcher->maxInFlight()
        ));

        $scheduler = new Scheduler(
            $this->dispatcher,
            $this->clock,
            $this->sleeper,
            $this->limiter,
            $this->bucket,
            $this->observer,
            $this->operationId,
            OperationType::Send,
            $this->continueAfterFailure,
            $this->operationDeadlineMs,
            $this->engine->settings()->maxInlineWaitMs,
        );

        $scheduler->run($runners);

        $result = $this->build($runners);

        $this->observer->emit(new OperationFinished(
            $this->operationId,
            OperationType::Send,
            $this->clock->nowUtcMillis(),
            $this->clock->monotonicMillis() - $startedAt,
            $result->summary(),
            $this->observer->failures()
        ));

        return $result;
    }

    /**
     * @param list<ChunkRunner> $runners
     */
    private function build(array $runners): SendResult
    {
        $outcomes = [];
        $failures = [];
        $uncorrelated = [];
        $warnings = [];

        foreach ($runners as $position => $runner) {
            $chunk = $this->chunks[$position];
            $outcome = $runner->outcome();

            if ($outcome === null) {
                // The scheduler always leaves a final state. This branch only
                // guards against a future change that forgets one.
                $outcome = new ChunkOutcome(
                    succeeded: false,
                    category: \Expo\Push\Result\FailureCategory::Skipped,
                    message: 'the SDK did not run this chunk',
                );
            }

            foreach ($outcome->warnings as $warning) {
                $warnings[] = $warning;
            }

            $response = $outcome->response;

            if ($response instanceof SendResponse) {
                foreach ($response->uncorrelatedIds as $id) {
                    $uncorrelated[] = $id;
                }
            }

            if ($outcome->succeeded && $response instanceof SendResponse) {
                foreach ($chunk->indexes as $position2 => $index) {
                    $outcomes[] = $this->acceptedOutcome(
                        $index,
                        $response->tickets[$position2] ?? null,
                        $outcome->anyAmbiguousAttempt
                    );
                }

                continue;
            }

            $failureIndex = count($failures);
            $failures[] = new RequestFailure(
                operation: OperationType::Send,
                chunkOrdinal: $chunk->ordinal,
                indexes: $chunk->indexes,
                ids: [],
                category: $outcome->category ?? \Expo\Push\Result\FailureCategory::Http,
                message: $outcome->message,
                httpStatus: $outcome->httpStatus,
                transportCode: $outcome->transportCode,
                expoErrors: $outcome->expoErrors,
                attempts: $outcome->attempts,
                retryable: $outcome->retryable,
                deferred: $outcome->deferred,
                earliestRetryAtUtcMs: $outcome->earliestRetryAtUtcMs,
            );

            foreach ($chunk->indexes as $index) {
                $outcomes[] = $this->failedOutcome($index, $outcome, $failureIndex);
            }
        }

        usort(
            $outcomes,
            static fn (NotificationOutcome $a, NotificationOutcome $b): int => $a->index <=> $b->index
        );

        return new SendResult($outcomes, $failures, array_values(array_unique($uncorrelated)), $warnings);
    }

    private function acceptedOutcome(int $index, ?PushTicket $ticket, bool $ambiguous): NotificationOutcome
    {
        if ($ticket === null) {
            // The request reached Expo and Expo answered. The entry is not
            // readable, so Expo may well have accepted this notification. A
            // resend can show it twice, whatever the earlier attempts did.
            return $this->outcomeFor(
                $index,
                Acceptance::Unknown,
                null,
                null,
                true,
                null,
                RecoveryDisposition::NeedsIntervention,
                'the ticket entry at this position was malformed, so acceptance is unknown'
            );
        }

        if ($ticket->isOk()) {
            return $this->outcomeFor(
                $index,
                Acceptance::Accepted,
                $ticket,
                null,
                $ambiguous,
                null,
                RecoveryDisposition::None,
                $ambiguous
                    ? 'an earlier attempt was ambiguous, so the device can show this notification twice'
                    : null
            );
        }

        if ($ambiguous) {
            return $this->outcomeFor(
                $index,
                Acceptance::Unknown,
                $ticket,
                null,
                true,
                null,
                // The work stays open whatever the code says: an earlier attempt
                // can already have been accepted, so only the application can
                // weigh a repeat.
                self::ticketRecovery($ticket) === RecoveryDisposition::Retryable
                    ? RecoveryDisposition::Retryable
                    : RecoveryDisposition::NeedsIntervention,
                'the last answer rejected this notification, and an earlier ambiguous attempt can already have '
                . 'been accepted'
            );
        }

        return $this->outcomeFor(
            $index,
            Acceptance::NotAccepted,
            $ticket,
            NotAcceptedReason::Rejected,
            false,
            null,
            self::ticketRecovery($ticket)
        );
    }

    private function failedOutcome(int $index, ChunkOutcome $outcome, int $failureIndex): NotificationOutcome
    {
        // The chunk owns the retryability of everything that it held, and of
        // nothing else.
        $recovery = $outcome->retryable
            ? RecoveryDisposition::Retryable
            : RecoveryDisposition::NeedsIntervention;

        if (!$outcome->dispatched) {
            return $this->outcomeFor(
                $index,
                Acceptance::NotAttempted,
                null,
                null,
                false,
                $failureIndex,
                $recovery,
                $outcome->message,
                $outcome->earliestRetryAtUtcMs
            );
        }

        if ($outcome->knownNotAccepted()) {
            return $this->outcomeFor(
                $index,
                Acceptance::NotAccepted,
                null,
                $outcome->serverRefused ? NotAcceptedReason::Rejected : NotAcceptedReason::NotTransmitted,
                false,
                $failureIndex,
                $recovery,
                $outcome->message,
                $outcome->earliestRetryAtUtcMs
            );
        }

        return $this->outcomeFor(
            $index,
            Acceptance::Unknown,
            null,
            null,
            true,
            $failureIndex,
            $recovery,
            $outcome->message,
            $outcome->earliestRetryAtUtcMs
        );
    }

    /**
     * What one rejected ticket leaves open.
     *
     * Expo answers with an error code for this one device, and the code decides:
     *
     * - `MessageRateExceeded` can pass later on its own.
     * - A credential error needs a fix in the Expo dashboard first. The token
     *   stays valid, so the work stays open.
     * - `DeviceNotRegistered` and `MessageTooBig` close the work. No repeat of
     *   the same message to the same device can pass.
     * - A code that the SDK does not know says nothing either way.
     */
    private static function ticketRecovery(PushTicket $ticket): RecoveryDisposition
    {
        return RecoveryDisposition::forClassification($ticket->classification());
    }

    private function outcomeFor(
        int $index,
        Acceptance $acceptance,
        ?PushTicket $ticket,
        ?NotAcceptedReason $reason,
        bool $duplicateRisk,
        ?int $failureIndex,
        RecoveryDisposition $recovery,
        ?string $detail = null,
        ?int $earliestRetryAtUtcMs = null,
    ): NotificationOutcome {
        $planned = $this->plan->notification($index);

        if ($planned === null) {
            throw new \LogicException(sprintf('The plan has no notification at index %d.', $index));
        }

        return new NotificationOutcome(
            index: $planned->index,
            messageKey: $planned->messageKey,
            recipientIndex: $planned->recipientIndex,
            token: $planned->token,
            acceptance: $acceptance,
            ticket: $ticket,
            reason: $reason,
            duplicateRisk: $duplicateRisk,
            reference: $planned->reference,
            failureIndex: $failureIndex,
            earliestRetryAtUtcMs: $earliestRetryAtUtcMs,
            detail: $detail,
            recovery: $recovery,
        );
    }
}
