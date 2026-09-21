<?php

declare(strict_types=1);

namespace Expo\Push\Result;

use Expo\Push\Exception\InvalidStorageException;
use Expo\Push\Exception\SendFailedException;
use Expo\Push\PushTicket;
use Expo\Push\PushToken;
use Expo\Push\Storage\StorageEnvelope;
use Expo\Push\TicketCollection;
use JsonSerializable;

/**
 * The whole answer of one `Expo::send()` call.
 *
 * The result answers four questions for every notification of your input:
 *
 * - What did Expo accept?
 * - What is known not to be accepted?
 * - What stays uncertain?
 * - What did the SDK never try?
 *
 * The outcomes keep the input order whatever order the requests finished in.
 */
final readonly class SendResult implements JsonSerializable
{
    public const string STORAGE_TYPE = 'expo.send_result';

    /**
     * @param list<NotificationOutcome> $outcomes         one entry for each notification, in input order
     * @param list<RequestFailure>      $requestFailures  one entry for each request that produced no usable answer
     * @param list<string>              $uncorrelatedIds  receipt IDs that the SDK read but cannot map to a device
     * @param list<string>              $warnings         redacted notes about contradictions in the answers
     */
    public function __construct(
        public array $outcomes = [],
        public array $requestFailures = [],
        public array $uncorrelatedIds = [],
        public array $warnings = [],
    ) {
    }

    /**
     * The number of notifications in the operation.
     */
    public function count(): int
    {
        return count($this->outcomes);
    }

    public function isEmpty(): bool
    {
        return $this->outcomes === [];
    }

    /**
     * Every outcome, in input order.
     *
     * @return list<NotificationOutcome>
     */
    public function outcomes(): array
    {
        return $this->outcomes;
    }

    public function outcome(int $index): ?NotificationOutcome
    {
        foreach ($this->outcomes as $outcome) {
            if ($outcome->index === $index) {
                return $outcome;
            }
        }

        return null;
    }

    /**
     * The real tickets that Expo sent, in input order.
     *
     * The collection never holds an invented ticket. A request that failed
     * contributes nothing here, so an empty collection does not mean success.
     */
    public function tickets(): TicketCollection
    {
        $tickets = [];

        foreach ($this->outcomes as $outcome) {
            if ($outcome->ticket !== null) {
                $tickets[] = $outcome->ticket;
            }
        }

        return new TicketCollection($tickets);
    }

    /**
     * @return list<RequestFailure>
     */
    public function requestFailures(): array
    {
        return $this->requestFailures;
    }

    /**
     * @return list<NotificationOutcome>
     */
    public function accepted(): array
    {
        return $this->withAcceptance(Acceptance::Accepted);
    }

    /**
     * @return list<NotificationOutcome>
     */
    public function notAccepted(): array
    {
        return $this->withAcceptance(Acceptance::NotAccepted);
    }

    /**
     * @return list<NotificationOutcome>
     */
    public function unknown(): array
    {
        return $this->withAcceptance(Acceptance::Unknown);
    }

    /**
     * @return list<NotificationOutcome>
     */
    public function notAttempted(): array
    {
        return $this->withAcceptance(Acceptance::NotAttempted);
    }

    /**
     * Every notification that an earlier ambiguous attempt can already have sent.
     *
     * A device can show these twice. That is the price of the delivery oriented
     * default policy.
     *
     * @return list<NotificationOutcome>
     */
    public function duplicateRisk(): array
    {
        return array_values(array_filter(
            $this->outcomes,
            static fn (NotificationOutcome $outcome): bool => $outcome->duplicateRisk
        ));
    }

    /**
     * One receipt reference for each accepted notification with a usable ID.
     *
     * Store these, wait, then give them to `Expo::receipts()`.
     *
     * @return list<ReceiptReference>
     */
    public function receiptReferences(): array
    {
        $references = [];

        foreach ($this->outcomes as $outcome) {
            $reference = $outcome->receiptReference();

            if ($reference !== null) {
                $references[] = $reference;
            }
        }

        return $references;
    }

    /**
     * Receipt IDs that the SDK read but cannot trust to belong to a device.
     *
     * A ticket count that does not match the request makes every position
     * untrustworthy. The IDs stay here so that you do not lose them.
     *
     * @return list<string>
     */
    public function uncorrelatedReceiptIds(): array
    {
        return $this->uncorrelatedIds;
    }

    /**
     * The devices that Expo marked `DeviceNotRegistered`. Delete these tokens.
     *
     * @return list<PushToken>
     */
    public function unregisteredTokens(): array
    {
        return $this->tickets()->unregisteredTokens();
    }

    /**
     * True when every notification is accepted and no request failed.
     *
     * The answer reads the evidence, not the label. An outcome that claims
     * acceptance without a successful ticket and a receipt ID never counts, so
     * a restored result cannot report more than it can show.
     */
    public function isCompleteSuccess(): bool
    {
        if ($this->requestFailures !== []) {
            return false;
        }

        if ($this->outcomes === []) {
            return true;
        }

        foreach ($this->outcomes as $outcome) {
            // receiptId() already demands a ticket that reports success, so
            // this covers both halves of the evidence.
            if (!$outcome->isAccepted() || $outcome->receiptId() === null) {
                return false;
            }
        }

        return true;
    }

    public function hasRequestFailures(): bool
    {
        return $this->requestFailures !== [];
    }

    /**
     * True when at least one well formed ticket reports a device error.
     *
     * This is not a request failure. The request worked and Expo refused one or
     * more devices.
     */
    public function hasTicketErrors(): bool
    {
        return $this->tickets()->hasErrors();
    }

    /**
     * True when anything at all is unresolved.
     *
     * The answer reads the same rule as `recoverable()`, so the two can never
     * disagree. A request failure counts. So does an open notification without
     * one: Expo can refuse a single device with `MessageRateExceeded`, and that
     * notification still needs a decision.
     */
    public function needsAttention(): bool
    {
        if ($this->requestFailures !== []) {
            return true;
        }

        foreach ($this->outcomes as $outcome) {
            if ($outcome->isOpen()) {
                return true;
            }
        }

        return false;
    }

    /**
     * The earliest UTC moment at which a retry of the failed work makes sense.
     */
    public function earliestRetryAtUtcMs(): ?int
    {
        $earliest = null;

        foreach ($this->requestFailures as $failure) {
            if ($failure->earliestRetryAtUtcMs === null) {
                continue;
            }

            $earliest = $earliest === null
                ? $failure->earliestRetryAtUtcMs
                : min($earliest, $failure->earliestRetryAtUtcMs);
        }

        return $earliest;
    }

    /**
     * Redacted notes about contradictions, such as an answer with both tickets
     * and request level errors.
     *
     * @return list<string>
     */
    public function warnings(): array
    {
        return $this->warnings;
    }

    /**
     * The notifications that still need a decision, with the duplicate risk visible.
     *
     * Store it, and let a worker decide what to send again. The SDK never resends
     * for you.
     */
    public function recoverable(): RecoverableWork
    {
        return RecoverableWork::fromSendResult($this);
    }

    /**
     * Raises when at least one request produced no usable answer.
     *
     * The exception carries this whole result, so nothing is lost. A well formed
     * error ticket never raises: that is data about one device, not a failure of
     * the request.
     *
     * @throws SendFailedException
     */
    public function throwIfRequestFailed(): self
    {
        if ($this->requestFailures !== []) {
            throw new SendFailedException($this);
        }

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toStorageArray(): array
    {
        return StorageEnvelope::wrap(self::STORAGE_TYPE, [
            'outcomes' => array_map(
                static fn (NotificationOutcome $outcome): array => $outcome->toStorageArray(),
                $this->outcomes
            ),
            'requestFailures' => array_map(
                static fn (RequestFailure $failure): array => $failure->toStorageArray(),
                $this->requestFailures
            ),
            'uncorrelatedIds' => $this->uncorrelatedIds,
            'warnings' => $this->warnings,
        ]);
    }

    /**
     * @param array<string, mixed> $stored
     *
     * @throws InvalidStorageException
     */
    public static function fromStorageArray(array $stored): self
    {
        $data = StorageEnvelope::unwrap(self::STORAGE_TYPE, $stored);

        $result = new self(
            outcomes: array_map(
                static fn (array $entry): NotificationOutcome => NotificationOutcome::fromStorageArray($entry),
                StorageEnvelope::listOfArrays(self::STORAGE_TYPE, $data, 'outcomes')
            ),
            requestFailures: array_map(
                static fn (array $entry): RequestFailure => RequestFailure::fromStorageArray($entry),
                StorageEnvelope::listOfArrays(self::STORAGE_TYPE, $data, 'requestFailures')
            ),
            uncorrelatedIds: StorageEnvelope::listOfStrings(self::STORAGE_TYPE, $data, 'uncorrelatedIds'),
            warnings: StorageEnvelope::listOfStrings(self::STORAGE_TYPE, $data, 'warnings'),
        );

        $result->assertFailuresMatchOutcomes();

        return $result;
    }

    /**
     * Refuses an outcome that points at evidence which is not there.
     *
     * One outcome names the request failure that explains it, by position. A
     * position outside the list, or a failure that never held this
     * notification, means that the two lists do not belong together.
     *
     * @throws InvalidStorageException
     */
    private function assertFailuresMatchOutcomes(): void
    {
        $count = count($this->requestFailures);

        foreach ($this->outcomes as $outcome) {
            $index = $outcome->failureIndex;

            if ($index === null) {
                continue;
            }

            if ($index >= $count) {
                throw new InvalidStorageException(sprintf(
                    'The stored %s has an outcome at index %d that points at request failure %d of %d.',
                    self::STORAGE_TYPE,
                    $outcome->index,
                    $index,
                    $count
                ));
            }

            if (!in_array($outcome->index, $this->requestFailures[$index]->indexes, true)) {
                throw new InvalidStorageException(sprintf(
                    'The stored %s has an outcome at index %d whose request failure never held it.',
                    self::STORAGE_TYPE,
                    $outcome->index
                ));
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function jsonSerialize(): array
    {
        return $this->toStorageArray();
    }

    /**
     * A short count for a log line. It holds no token.
     *
     * @return array<string, int>
     */
    public function summary(): array
    {
        return [
            'total' => $this->count(),
            'accepted' => count($this->accepted()),
            'notAccepted' => count($this->notAccepted()),
            'unknown' => count($this->unknown()),
            'notAttempted' => count($this->notAttempted()),
            'duplicateRisk' => count($this->duplicateRisk()),
            'requestFailures' => count($this->requestFailures),
        ];
    }

    /**
     * @return list<NotificationOutcome>
     */
    private function withAcceptance(Acceptance $acceptance): array
    {
        return array_values(array_filter(
            $this->outcomes,
            static fn (NotificationOutcome $outcome): bool => $outcome->acceptance === $acceptance
        ));
    }

    /**
     * Every ticket of this result, keyed by receipt ID.
     *
     * @return array<string, PushTicket>
     */
    public function ticketsById(): array
    {
        $byId = [];

        foreach ($this->outcomes as $outcome) {
            if ($outcome->ticket?->id !== null) {
                $byId[$outcome->ticket->id] = $outcome->ticket;
            }
        }

        return $byId;
    }
}
