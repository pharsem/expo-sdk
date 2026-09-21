<?php

declare(strict_types=1);

namespace Expo\Push\Result;

use Expo\Push\Exception\InvalidStorageException;
use Expo\Push\PushReceipt;
use Expo\Push\PushToken;
use Expo\Push\Storage\StorageEnvelope;
use JsonSerializable;

/**
 * One requested receipt ID and what happened to it.
 *
 * Two rules hold for every entry:
 *
 * - A receipt belongs to the state `Returned`, and to no other state.
 * - The entry carries the first reference that asked for the ID. Every later
 *   reference for the same ID goes to `otherReferences`, so a repeated ID keeps
 *   the correlation of each notification that asked.
 */
final readonly class ReceiptEntry implements JsonSerializable
{
    public const string STORAGE_TYPE = 'expo.receipt_entry';

    /**
     * @param string           $id           the receipt ID that you asked for
     * @param ReceiptState     $state        what the SDK knows about this ID
     * @param PushReceipt|null $receipt      the receipt, only when the state is `Returned`
     * @param PushToken|null   $token        the device, when the SDK knows it
     * @param int|null         $notificationIndex the position in the send operation that produced the ID
     * @param string|null      $reference    your own correlation value
     * @param int|null         $failureIndex the position in `ReceiptResult::requestFailures()`
     * @param list<ReceiptReference> $otherReferences every later reference for the same ID
     */
    public function __construct(
        public string $id,
        public ReceiptState $state,
        public ?PushReceipt $receipt = null,
        public ?PushToken $token = null,
        public ?int $notificationIndex = null,
        public ?string $reference = null,
        public ?int $failureIndex = null,
        public array $otherReferences = [],
    ) {
    }

    public function isReturned(): bool
    {
        return $this->state === ReceiptState::Returned;
    }

    /**
     * True when the lookup worked and the receipt reports a failure.
     */
    public function isError(): bool
    {
        return $this->receipt?->isError() === true;
    }

    /**
     * Every reference that asked for this ID, in input order.
     *
     * A lookup of the same ID twice keeps both. The first one is on the entry
     * itself, and the rest are in `otherReferences`.
     *
     * @return list<ReceiptReference>
     */
    public function references(): array
    {
        return [
            new ReceiptReference($this->id, $this->token, $this->notificationIndex, $this->reference),
            ...$this->otherReferences,
        ];
    }

    /**
     * Every position of the send operation that asked for this ID.
     *
     * @return list<int>
     */
    public function notificationIndexes(): array
    {
        $indexes = [];

        foreach ($this->references() as $reference) {
            if ($reference->notificationIndex !== null) {
                $indexes[$reference->notificationIndex] = true;
            }
        }

        return array_map(intval(...), array_keys($indexes));
    }

    /**
     * @return array<string, mixed>
     */
    public function toStorageArray(): array
    {
        $data = [
            'id' => $this->id,
            'state' => $this->state->value,
            'receipt' => $this->receipt?->toStorageArray(),
            'token' => $this->token?->value,
            'notificationIndex' => $this->notificationIndex,
            'reference' => $this->reference,
            'failureIndex' => $this->failureIndex,
        ];

        // Most entries have one reference, and the fields above already hold it.
        // The list appears only when a repeated ID brought more.
        if ($this->otherReferences !== []) {
            $data['otherReferences'] = array_map(
                static fn (ReceiptReference $reference): array => $reference->toStorageArray(),
                $this->otherReferences
            );
        }

        return StorageEnvelope::wrap(self::STORAGE_TYPE, $data);
    }

    /**
     * @param array<string, mixed> $stored
     *
     * @throws InvalidStorageException
     */
    public static function fromStorageArray(array $stored): self
    {
        $data = StorageEnvelope::unwrap(self::STORAGE_TYPE, $stored);

        $id = $data['id'] ?? null;
        $state = is_string($data['state'] ?? null) ? ReceiptState::tryFrom((string) $data['state']) : null;

        if (!is_string($id) || $id === '') {
            throw InvalidStorageException::missingField(self::STORAGE_TYPE, 'id');
        }

        if ($state === null) {
            throw InvalidStorageException::missingField(self::STORAGE_TYPE, 'state');
        }

        $receipt = $data['receipt'] ?? null;
        $token = $data['token'] ?? null;

        // A returned entry without a receipt would report a complete lookup and
        // give nothing back. A receipt under another state means the same kind of
        // contradiction. The SDK rejects both.
        if ($state === ReceiptState::Returned && !is_array($receipt)) {
            throw new InvalidStorageException(sprintf(
                'The stored %s has the state "returned" and no receipt. A returned entry always holds one.',
                self::STORAGE_TYPE
            ));
        }

        if ($state !== ReceiptState::Returned && $receipt !== null) {
            throw new InvalidStorageException(sprintf(
                'The stored %s has the state "%s" and a receipt. Only a returned entry holds one.',
                self::STORAGE_TYPE,
                $state->value
            ));
        }

        $others = [];

        if (array_key_exists('otherReferences', $data)) {
            $others = array_map(
                static fn (array $entry): ReceiptReference => ReceiptReference::fromStorageArray($entry),
                StorageEnvelope::listOfArrays(self::STORAGE_TYPE, $data, 'otherReferences')
            );
        }

        if ($token !== null && (!is_string($token) || $token === '')) {
            throw InvalidStorageException::missingField(self::STORAGE_TYPE, 'token');
        }

        $entry = new self(
            id: $id,
            state: $state,
            receipt: is_array($receipt) ? PushReceipt::fromStorageArray($receipt) : null,
            token: is_string($token) ? new PushToken($token) : null,
            notificationIndex: self::optionalIndex($data, 'notificationIndex'),
            reference: self::optionalString($data, 'reference'),
            failureIndex: self::optionalIndex($data, 'failureIndex'),
            otherReferences: $others,
        );

        foreach ($others as $reference) {
            self::assertReferenceBelongs($entry, $reference);
        }

        // The entry, its receipt and its references all name one notification.
        // A stored array that gives them two devices is broken, not merged.
        $receiptToken = $entry->receipt?->token;

        if ($receiptToken !== null && $entry->token !== null && $receiptToken->value !== $entry->token->value) {
            throw new InvalidStorageException(sprintf(
                'The stored %s holds a receipt of another device. The association is broken.',
                self::STORAGE_TYPE
            ));
        }

        if ($entry->receipt !== null && $entry->receipt->id !== $entry->id) {
            throw new InvalidStorageException(sprintf(
                'The stored %s holds a receipt with another ID. The association is broken.',
                self::STORAGE_TYPE
            ));
        }

        return $entry;
    }

    /**
     * Refuses a later reference that belongs to another ID or another device.
     *
     * Every reference of one entry asks about the same receipt ID. A stored
     * list that mixes two IDs would give one notification the correlation of
     * another one.
     *
     * @throws InvalidStorageException
     */
    private static function assertReferenceBelongs(self $entry, ReceiptReference $reference): void
    {
        if ($reference->id !== $entry->id) {
            throw new InvalidStorageException(sprintf(
                'The stored %s "%s" holds a later reference for "%s". Every reference names one ID.',
                self::STORAGE_TYPE,
                $entry->id,
                $reference->id
            ));
        }

        $token = $reference->token;

        if ($token !== null && $entry->token !== null && $token->value !== $entry->token->value) {
            throw new InvalidStorageException(sprintf(
                'The stored %s "%s" holds a later reference of another device. The association is broken.',
                self::STORAGE_TYPE,
                $entry->id
            ));
        }
    }

    /**
     * @param array<string, mixed> $data
     *
     * @throws InvalidStorageException
     */
    private static function optionalIndex(array $data, string $field): ?int
    {
        $value = $data[$field] ?? null;

        if ($value === null) {
            return null;
        }

        if (!is_int($value) || $value < 0) {
            throw InvalidStorageException::missingField(self::STORAGE_TYPE, $field);
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @throws InvalidStorageException
     */
    private static function optionalString(array $data, string $field): ?string
    {
        $value = $data[$field] ?? null;

        if ($value === null) {
            return null;
        }

        if (!is_string($value)) {
            throw InvalidStorageException::missingField(self::STORAGE_TYPE, $field);
        }

        return $value;
    }

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function jsonSerialize(): array
    {
        return $this->toStorageArray();
    }
}
