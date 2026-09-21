<?php

declare(strict_types=1);

namespace Expo\Push\Result;

use Expo\Push\Exception\InvalidStorageException;
use Expo\Push\PushToken;
use Expo\Push\Storage\StorageEnvelope;
use JsonSerializable;

/**
 * One receipt ID and everything the SDK knows about the notification behind it.
 *
 * Store these between the send and the receipt lookup. The reference keeps the
 * device token and the position of the notification in the original operation,
 * so a later lookup in another process still knows which device it talks about.
 */
final readonly class ReceiptReference implements JsonSerializable
{
    public const string STORAGE_TYPE = 'expo.receipt_reference';

    /**
     * @param string       $id                the receipt ID from an accepted ticket
     * @param PushToken|null $token           the device, when the SDK knows it
     * @param int|null     $notificationIndex the position in the operation that produced the ticket
     * @param string|null  $reference         your own correlation value from the message
     */
    public function __construct(
        public string $id,
        public ?PushToken $token = null,
        public ?int $notificationIndex = null,
        public ?string $reference = null,
    ) {
    }

    /**
     * A copy with a token, when the caller knows one and the reference does not.
     */
    public function withToken(PushToken $token): self
    {
        return new self($this->id, $token, $this->notificationIndex, $this->reference);
    }

    /**
     * @return array<string, mixed>
     */
    public function toStorageArray(): array
    {
        return StorageEnvelope::wrap(self::STORAGE_TYPE, array_filter([
            'id' => $this->id,
            'token' => $this->token?->value,
            'notificationIndex' => $this->notificationIndex,
            'reference' => $this->reference,
        ], static fn (mixed $value): bool => $value !== null));
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

        if (!is_string($id) || $id === '') {
            throw InvalidStorageException::missingField(self::STORAGE_TYPE, 'id');
        }

        $token = $data['token'] ?? null;
        $index = $data['notificationIndex'] ?? null;
        $reference = $data['reference'] ?? null;

        // A present field of the wrong type is broken data, not an absent one.
        // A correlation that quietly turns into null points at nothing.
        if ($token !== null && (!is_string($token) || !PushToken::isValid($token))) {
            throw InvalidStorageException::missingField(self::STORAGE_TYPE, 'token');
        }

        if ($index !== null && (!is_int($index) || $index < 0)) {
            throw InvalidStorageException::missingField(self::STORAGE_TYPE, 'notificationIndex');
        }

        if ($reference !== null && !is_string($reference)) {
            throw InvalidStorageException::missingField(self::STORAGE_TYPE, 'reference');
        }

        return new self(
            id: $id,
            token: is_string($token) ? new PushToken($token) : null,
            notificationIndex: is_int($index) ? $index : null,
            reference: is_string($reference) ? $reference : null,
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
