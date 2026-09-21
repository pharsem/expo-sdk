<?php

declare(strict_types=1);

namespace Expo\Push\Execution;

use Expo\Push\Http\HttpResponse;
use Expo\Push\Http\RequestFactory;
use Expo\Push\Observability\OperationFinished;
use Expo\Push\Observability\OperationStarted;
use Expo\Push\Observability\SafeObserver;
use Expo\Push\Plan\ReceiptChunk;
use Expo\Push\Plan\ReceiptPlan;
use Expo\Push\Protocol\ReceiptResponse;
use Expo\Push\Protocol\ReceiptResponseParser;
use Expo\Push\RateLimit\NullRateLimiter;
use Expo\Push\Result\FailureCategory;
use Expo\Push\Result\OperationType;
use Expo\Push\Result\ReceiptEntry;
use Expo\Push\Result\ReceiptResult;
use Expo\Push\Result\ReceiptState;
use Expo\Push\Result\RequestFailure;
use Expo\Push\Retry\RetryEngine;
use Expo\Push\Support\Clock;
use Expo\Push\Support\Sleeper;

/**
 * Runs one receipt lookup and turns the chunk outcomes into a `ReceiptResult`.
 *
 * A lookup has no duplicate risk, so the retry rules are simpler than for a
 * send. The states stay strict: a valid answer without an entry gives `Missing`,
 * a broken entry gives `Malformed`, a failed request gives `LookupFailed`, and a
 * chunk that never went out gives `NotAttempted`.
 *
 * The notification limiter never applies here. Expo counts notifications, and a
 * receipt lookup sends none. A lookup of 1000 IDs would also never fit a limiter
 * of 600 permits, so the limit would block work that it was never meant to bound.
 */
final readonly class ReceiptOperation
{
    /**
     * @param list<ReceiptChunk> $chunks
     */
    public function __construct(
        private ReceiptPlan $plan,
        private array $chunks,
        private RequestFactory $requests,
        private RetryEngine $engine,
        private Dispatcher $dispatcher,
        private Clock $clock,
        private Sleeper $sleeper,
        private string $bucket,
        private SafeObserver $observer,
        private string $operationId,
        private bool $continueAfterFailure,
        private ?int $operationDeadlineMs,
    ) {
    }

    public function run(): ReceiptResult
    {
        if ($this->chunks === []) {
            return new ReceiptResult();
        }

        $startedAt = $this->clock->monotonicMillis();
        $tokensById = $this->plan->tokensById();
        $runners = [];

        foreach ($this->chunks as $chunk) {
            $ids = $chunk->ids;

            $runners[] = new ChunkRunner(
                ordinal: $chunk->ordinal,
                size: $chunk->count(),
                request: $this->requests->receipts($chunk->body()),
                engine: $this->engine,
                operation: OperationType::Receipts,
                clock: $this->clock,
                parser: static fn (HttpResponse $response): ReceiptResponse
                    => ReceiptResponseParser::parse($response->body, $ids, $tokensById),
            );
        }

        $this->observer->emit(new OperationStarted(
            $this->operationId,
            OperationType::Receipts,
            $this->clock->nowUtcMillis(),
            $this->plan->count(),
            count($this->chunks),
            $this->dispatcher->maxInFlight()
        ));

        $scheduler = new Scheduler(
            $this->dispatcher,
            $this->clock,
            $this->sleeper,
            new NullRateLimiter(),
            $this->bucket,
            $this->observer,
            $this->operationId,
            OperationType::Receipts,
            $this->continueAfterFailure,
            $this->operationDeadlineMs,
        );

        $scheduler->run($runners);

        $result = $this->build($runners);

        $this->observer->emit(new OperationFinished(
            $this->operationId,
            OperationType::Receipts,
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
    private function build(array $runners): ReceiptResult
    {
        $states = [];
        $receipts = [];
        $failureIndexes = [];
        $failures = [];
        $unexpected = [];
        $warnings = [];

        foreach ($this->plan->ids as $id) {
            $states[$id] = ReceiptState::NotAttempted;
        }

        foreach ($runners as $position => $runner) {
            $chunk = $this->chunks[$position];
            $outcome = $runner->outcome();

            if ($outcome === null) {
                continue;
            }

            foreach ($outcome->warnings as $warning) {
                $warnings[] = $warning;
            }

            $response = $outcome->response;

            if ($outcome->succeeded && $response instanceof ReceiptResponse) {
                foreach ($response->unexpectedIds as $id) {
                    $unexpected[] = $id;
                }

                $malformed = array_flip($response->malformedIds);

                foreach ($chunk->ids as $id) {
                    if (isset($response->receipts[$id])) {
                        $states[$id] = ReceiptState::Returned;
                        $receipts[$id] = $response->receipts[$id];

                        continue;
                    }

                    $states[$id] = isset($malformed[$id]) ? ReceiptState::Malformed : ReceiptState::Missing;
                }

                continue;
            }

            $failureIndex = count($failures);
            $failures[] = new RequestFailure(
                operation: OperationType::Receipts,
                chunkOrdinal: $chunk->ordinal,
                indexes: [],
                ids: $chunk->ids,
                category: $outcome->category ?? FailureCategory::Http,
                message: $outcome->message,
                httpStatus: $outcome->httpStatus,
                transportCode: $outcome->transportCode,
                expoErrors: $outcome->expoErrors,
                attempts: $outcome->attempts,
                retryable: $outcome->retryable,
                deferred: $outcome->deferred,
                earliestRetryAtUtcMs: $outcome->earliestRetryAtUtcMs,
            );

            foreach ($chunk->ids as $id) {
                $states[$id] = $outcome->dispatched ? ReceiptState::LookupFailed : ReceiptState::NotAttempted;
                $failureIndexes[$id] = $failureIndex;
            }
        }

        $entries = [];

        foreach ($this->plan->ids as $id) {
            $reference = $this->plan->referencesById[$id] ?? null;
            $receipt = $receipts[$id] ?? null;
            $token = $reference?->token;

            if ($token === null) {
                $token = $receipt?->token;
            }

            $entries[] = new ReceiptEntry(
                id: $id,
                state: $states[$id] ?? ReceiptState::NotAttempted,
                receipt: $receipt,
                token: $token,
                notificationIndex: $reference?->notificationIndex,
                reference: $reference?->reference,
                failureIndex: $failureIndexes[$id] ?? null,
            );
        }

        return new ReceiptResult(
            entries: $entries,
            requestFailures: $failures,
            unexpectedIds: array_values(array_unique($unexpected)),
            warnings: $warnings,
        );
    }
}
