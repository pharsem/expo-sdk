<?php

declare(strict_types=1);

namespace Expo\Push\Result;

use Expo\Push\Exception\InvalidStorageException;
use Expo\Push\Exception\ReceiptLookupFailedException;
use Expo\Push\PushError;
use Expo\Push\PushReceipt;
use Expo\Push\PushToken;
use Expo\Push\ReceiptCollection;
use Expo\Push\Storage\StorageEnvelope;
use JsonSerializable;

/**
 * The whole answer of one `Expo::receipts()` call.
 *
 * The result owns the coverage of the lookup: which IDs came back, which were
 * missing, which entries were malformed, which requests failed and which IDs the
 * SDK never asked about. A filter on the returned receipts cannot change any of
 * that.
 */
final readonly class ReceiptResult implements JsonSerializable
{
    public const string STORAGE_TYPE = 'expo.receipt_result';

    /**
     * @param list<ReceiptEntry>   $entries         one entry for each unique requested ID, in input order
     * @param list<RequestFailure> $requestFailures one entry for each request that produced no usable answer
     * @param list<string>         $unexpectedIds   IDs that Expo sent and the SDK did not ask for
     * @param list<string>         $warnings        redacted notes about contradictions in the answers
     * @param list<string>         $conflicts       IDs where two lookups returned receipts that do not agree
     */
    public function __construct(
        public array $entries = [],
        public array $requestFailures = [],
        public array $unexpectedIds = [],
        public array $warnings = [],
        public array $conflicts = [],
    ) {
    }

    public function count(): int
    {
        return count($this->entries);
    }

    public function isEmpty(): bool
    {
        return $this->entries === [];
    }

    /**
     * @return list<ReceiptEntry>
     */
    public function entries(): array
    {
        return $this->entries;
    }

    public function entry(string $id): ?ReceiptEntry
    {
        foreach ($this->entries as $entry) {
            if ($entry->id === $id) {
                return $entry;
            }
        }

        return null;
    }

    public function state(string $id): ?ReceiptState
    {
        return $this->entry($id)?->state;
    }

    /**
     * The receipts that Expo returned, and nothing else.
     *
     * Filtering this collection never changes the coverage of the lookup.
     */
    public function receipts(): ReceiptCollection
    {
        $receipts = [];

        foreach ($this->entries as $entry) {
            if ($entry->receipt !== null) {
                $receipts[] = $entry->receipt;
            }
        }

        return new ReceiptCollection($receipts);
    }

    public function get(string $id): ?PushReceipt
    {
        return $this->entry($id)?->receipt;
    }

    /**
     * The receipts that Apple or Google took. This is not device delivery.
     */
    public function ok(): ReceiptCollection
    {
        return $this->receipts()->ok();
    }

    /**
     * The receipts that report a failure.
     */
    public function errors(): ReceiptCollection
    {
        return $this->receipts()->errors();
    }

    public function hasErrors(): bool
    {
        return $this->receipts()->hasErrors();
    }

    /**
     * @return list<string>
     */
    public function returnedIds(): array
    {
        return $this->idsWithState(ReceiptState::Returned);
    }

    /**
     * The IDs that a valid answer did not hold.
     *
     * Missing can mean three things: the receipt is not ready, the ID is not
     * valid, or Expo no longer keeps the receipt. Ask again later, and stop after
     * a bounded number of tries.
     *
     * @return list<string>
     */
    public function missingIds(): array
    {
        return $this->idsWithState(ReceiptState::Missing);
    }

    /**
     * The IDs whose entry the SDK could not read.
     *
     * @return list<string>
     */
    public function malformedIds(): array
    {
        return $this->idsWithState(ReceiptState::Malformed);
    }

    /**
     * The IDs whose request produced no usable answer.
     *
     * @return list<string>
     */
    public function failedIds(): array
    {
        return $this->idsWithState(ReceiptState::LookupFailed);
    }

    /**
     * The IDs that the SDK never asked Expo about.
     *
     * @return list<string>
     */
    public function notAttemptedIds(): array
    {
        return $this->idsWithState(ReceiptState::NotAttempted);
    }

    /**
     * The IDs that you can ask about again: missing, malformed, failed or not attempted.
     *
     * @return list<string>
     */
    public function unresolvedIds(): array
    {
        $ids = [];

        foreach ($this->entries as $entry) {
            if ($entry->state !== ReceiptState::Returned) {
                $ids[] = $entry->id;
            }
        }

        return $ids;
    }

    /**
     * An alias of `missingIds()`. Prefer that name.
     *
     * "Pending" claims too much: a missing receipt can also mean an invalid ID
     * or a receipt that Expo no longer keeps.
     *
     * @return list<string>
     */
    public function pendingIds(): array
    {
        return $this->missingIds();
    }

    /**
     * @return list<RequestFailure>
     */
    public function requestFailures(): array
    {
        return $this->requestFailures;
    }

    public function hasRequestFailures(): bool
    {
        return $this->requestFailures !== [];
    }

    /**
     * IDs that Expo sent and that the SDK did not ask for.
     *
     * The SDK never attaches a token of another notification to one of these.
     *
     * @return list<string>
     */
    public function unexpectedIds(): array
    {
        return $this->unexpectedIds;
    }

    /**
     * @return list<string>
     */
    public function warnings(): array
    {
        return $this->warnings;
    }

    /**
     * IDs where two lookups returned receipts that do not agree.
     *
     * The result keeps the first receipt and records the ID here. It never
     * overwrites one answer with another one in silence.
     *
     * @return list<string>
     */
    public function conflicts(): array
    {
        return $this->conflicts;
    }

    /**
     * True when every requested ID came back with a receipt.
     */
    public function isComplete(): bool
    {
        foreach ($this->entries as $entry) {
            if ($entry->state !== ReceiptState::Returned) {
                return false;
            }
        }

        return true;
    }

    /**
     * The devices that Expo marked `DeviceNotRegistered`. Delete these tokens.
     *
     * The list holds only tokens with that explicit evidence, and only where the
     * SDK knows the device.
     *
     * @return list<PushToken>
     */
    public function unregisteredTokens(): array
    {
        $tokens = [];

        foreach ($this->entries as $entry) {
            if ($entry->receipt?->error !== PushError::DeviceNotRegistered) {
                continue;
            }

            $token = $entry->receipt->token ?? $entry->token;

            if ($token !== null) {
                $tokens[$token->value] = $token;
            }
        }

        return array_values($tokens);
    }

    /**
     * Joins a later lookup into this one.
     *
     * The rules:
     *
     * - A returned receipt never falls back to missing, malformed or failed.
     * - The same receipt twice stays one entry.
     * - Two returned receipts that do not agree keep the first one, and the ID
     *   goes to `conflicts()`.
     * - An ID that only the other result holds joins at the end.
     */
    #[\NoDiscard]
    public function merge(self $other): self
    {
        $offset = count($this->requestFailures);
        $entries = [];
        $index = [];

        foreach ($this->entries as $position => $entry) {
            $entries[$position] = $entry;
            $index[$entry->id] = $position;
        }

        $conflicts = $this->conflicts;

        foreach ($other->entries as $entry) {
            $shifted = $entry->failureIndex === null ? null : $entry->failureIndex + $offset;
            $position = $index[$entry->id] ?? null;

            if ($position === null) {
                $index[$entry->id] = count($entries);
                // Only the failure index moves. Everything else of the entry
                // stays, the later references included.
                $entries[] = new ReceiptEntry(
                    $entry->id,
                    $entry->state,
                    $entry->receipt,
                    $entry->token,
                    $entry->notificationIndex,
                    $entry->reference,
                    $shifted,
                    $entry->otherReferences,
                );

                continue;
            }

            $current = $entries[$position];

            // The correlation of the two entries joins, whichever state wins. A
            // later lookup that knows the device must not lose it, and an
            // earlier one must not lose it either.
            $token = $current->token ?? $entry->token;
            $notificationIndex = $current->notificationIndex ?? $entry->notificationIndex;
            $reference = $current->reference ?? $entry->reference;
            $others = self::joinReferences($current, $entry);

            if ($current->isReturned() && $entry->isReturned()) {
                if (!self::sameReceipt($current->receipt, $entry->receipt) && !in_array($entry->id, $conflicts, true)) {
                    $conflicts[] = $entry->id;
                }

                $entries[$position] = new ReceiptEntry(
                    $current->id,
                    $current->state,
                    $current->receipt,
                    $token ?? $current->receipt?->token,
                    $notificationIndex,
                    $reference,
                    $current->failureIndex,
                    $others,
                );

                continue;
            }

            $wins = self::rank($entry->state) > self::rank($current->state);

            $entries[$position] = new ReceiptEntry(
                $current->id,
                $wins ? $entry->state : $current->state,
                $wins ? $entry->receipt : $current->receipt,
                $token ?? ($wins ? $entry->receipt?->token : $current->receipt?->token),
                $notificationIndex,
                $reference,
                $wins ? $shifted : $current->failureIndex,
                $others,
            );
        }

        return new self(
            entries: array_values($entries),
            requestFailures: [...$this->requestFailures, ...$other->requestFailures],
            unexpectedIds: array_values(array_unique([...$this->unexpectedIds, ...$other->unexpectedIds])),
            warnings: [...$this->warnings, ...$other->warnings],
            conflicts: $conflicts,
        );
    }

    /**
     * Raises when at least one lookup request produced no usable answer.
     *
     * The exception carries this whole result. A receipt with the status `error`
     * never raises: that is data about one notification.
     *
     * @throws ReceiptLookupFailedException
     */
    public function throwIfLookupFailed(): self
    {
        if ($this->requestFailures !== []) {
            throw new ReceiptLookupFailedException($this);
        }

        return $this;
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
            'returned' => count($this->returnedIds()),
            'missing' => count($this->missingIds()),
            'malformed' => count($this->malformedIds()),
            'failed' => count($this->failedIds()),
            'notAttempted' => count($this->notAttemptedIds()),
            'requestFailures' => count($this->requestFailures),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toStorageArray(): array
    {
        return StorageEnvelope::wrap(self::STORAGE_TYPE, [
            'entries' => array_map(
                static fn (ReceiptEntry $entry): array => $entry->toStorageArray(),
                $this->entries
            ),
            'requestFailures' => array_map(
                static fn (RequestFailure $failure): array => $failure->toStorageArray(),
                $this->requestFailures
            ),
            'unexpectedIds' => $this->unexpectedIds,
            'warnings' => $this->warnings,
            'conflicts' => $this->conflicts,
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

        return new self(
            entries: array_map(
                static fn (array $entry): ReceiptEntry => ReceiptEntry::fromStorageArray($entry),
                StorageEnvelope::listOfArrays(self::STORAGE_TYPE, $data, 'entries')
            ),
            requestFailures: array_map(
                static fn (array $entry): RequestFailure => RequestFailure::fromStorageArray($entry),
                StorageEnvelope::listOfArrays(self::STORAGE_TYPE, $data, 'requestFailures')
            ),
            unexpectedIds: StorageEnvelope::listOfStrings(self::STORAGE_TYPE, $data, 'unexpectedIds'),
            warnings: StorageEnvelope::listOfStrings(self::STORAGE_TYPE, $data, 'warnings'),
            conflicts: StorageEnvelope::listOfStrings(self::STORAGE_TYPE, $data, 'conflicts'),
        );
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
     * @return list<string>
     */
    private function idsWithState(ReceiptState $state): array
    {
        $ids = [];

        foreach ($this->entries as $entry) {
            if ($entry->state === $state) {
                $ids[] = $entry->id;
            }
        }

        return $ids;
    }

    /**
     * Every reference of both entries but the first one, without a repeat.
     *
     * @return list<ReceiptReference>
     */
    private static function joinReferences(ReceiptEntry $current, ReceiptEntry $other): array
    {
        $seen = [];
        $joined = [];
        $first = true;

        foreach ([...$current->references(), ...$other->references()] as $reference) {
            $token = $reference->token;

            $key = sprintf(
                '%s|%s|%s',
                $token === null ? '' : $token->value,
                $reference->notificationIndex ?? '',
                $reference->reference ?? ''
            );

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;

            // The first one stays on the entry itself.
            if ($first) {
                $first = false;

                continue;
            }

            $joined[] = $reference;
        }

        return $joined;
    }

    /**
     * A higher rank replaces a lower one in a merge.
     */
    private static function rank(ReceiptState $state): int
    {
        return match ($state) {
            ReceiptState::Returned => 4,
            ReceiptState::Malformed => 3,
            ReceiptState::Missing => 2,
            ReceiptState::LookupFailed => 1,
            ReceiptState::NotAttempted => 0,
        };
    }

    private static function sameReceipt(?PushReceipt $first, ?PushReceipt $second): bool
    {
        if ($first === null || $second === null) {
            return $first === $second;
        }

        return $first->status === $second->status
            && $first->errorCode === $second->errorCode
            && $first->message === $second->message;
    }
}
