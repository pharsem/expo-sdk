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
     */
    public function __construct(
        public string $id,
        public ReceiptState $state,
        public ?PushReceipt $receipt = null,
        public ?PushToken $token = null,
        public ?int $notificationIndex = null,
        public ?string $reference = null,
        public ?int $failureIndex = null,
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
     * A copy with another state and receipt, for a merge.
     */
    public function with(ReceiptState $state, ?PushReceipt $receipt, ?int $failureIndex): self
    {
        return new self(
            $this->id,
            $state,
            $receipt,
            $this->token ?? $receipt?->token,
            $this->notificationIndex,
            $this->reference,
            $failureIndex,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toStorageArray(): array
    {
        return StorageEnvelope::wrap(self::STORAGE_TYPE, [
            'id' => $this->id,
            'state' => $this->state->value,
            'receipt' => $this->receipt?->toStorageArray(),
            'token' => $this->token?->value,
            'notificationIndex' => $this->notificationIndex,
            'reference' => $this->reference,
            'failureIndex' => $this->failureIndex,
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

        return new self(
            id: $id,
            state: $state,
            receipt: is_array($receipt) ? PushReceipt::fromStorageArray($receipt) : null,
            token: is_string($token) ? new PushToken($token) : null,
            notificationIndex: is_int($data['notificationIndex'] ?? null) ? (int) $data['notificationIndex'] : null,
            reference: is_string($data['reference'] ?? null) ? (string) $data['reference'] : null,
            failureIndex: is_int($data['failureIndex'] ?? null) ? (int) $data['failureIndex'] : null,
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
}
