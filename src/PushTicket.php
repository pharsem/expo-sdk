<?php

declare(strict_types=1);

namespace Expo\Push;

use Expo\Push\Exception\InvalidStorageException;
use Expo\Push\Result\ReceiptReference;
use Expo\Push\Storage\StorageEnvelope;
use JsonSerializable;

/**
 * The answer of Expo for one notification.
 *
 * A ticket with the status `ok` means that Expo accepted the message. It does not
 * mean that Apple or Google got it, and it never means that the device showed it.
 * Read the receipt for the handoff result.
 *
 * The SDK builds a ticket only from a well formed entry of the Expo answer. It
 * never invents a ticket for a request that failed, and it never turns a
 * malformed entry into a rejected device.
 */
final readonly class PushTicket implements JsonSerializable
{
    public const string STATUS_OK = 'ok';

    public const string STATUS_ERROR = 'error';

    public const string STORAGE_TYPE = 'expo.ticket';

    /**
     * @param string               $status    `ok` or `error`
     * @param string|null          $id        the receipt ID, always present when the status is `ok`
     * @param PushToken|null       $token     the device that this ticket belongs to
     * @param string|null          $message   the error text of Expo
     * @param PushError|null       $error     the typed error code, when the SDK knows it
     * @param string|null          $errorCode the raw error code of Expo, known or not
     * @param array<string, mixed> $details   the details object of Expo, kept as it arrived
     */
    public function __construct(
        public string $status,
        public ?string $id = null,
        public ?PushToken $token = null,
        public ?string $message = null,
        public ?PushError $error = null,
        public ?string $errorCode = null,
        public array $details = [],
    ) {
    }

    /**
     * Reads one entry of the `data` array of the send endpoint.
     *
     * Returns null when the entry is not a valid ticket. The caller then marks
     * that position unknown. A malformed entry never becomes a rejected device.
     *
     * @param array<string, mixed> $data
     */
    public static function fromExpoArray(array $data, ?PushToken $token = null): ?self
    {
        $status = $data['status'] ?? null;

        if ($status !== self::STATUS_OK && $status !== self::STATUS_ERROR) {
            return null;
        }

        $id = $data['id'] ?? null;

        if ($status === self::STATUS_OK && (!is_string($id) || $id === '')) {
            return null;
        }

        $details = isset($data['details']) && is_array($data['details']) ? $data['details'] : [];
        /** @var array<string, mixed> $details */
        $errorCode = isset($details['error']) && is_string($details['error']) ? $details['error'] : null;

        if ($token === null && isset($details['expoPushToken']) && is_string($details['expoPushToken'])) {
            $token = PushToken::tryFrom($details['expoPushToken']);
        }

        return new self(
            status: $status,
            id: is_string($id) && $id !== '' ? $id : null,
            token: $token,
            message: isset($data['message']) && is_string($data['message']) ? $data['message'] : null,
            error: $errorCode === null ? null : PushError::tryFrom($errorCode),
            errorCode: $errorCode,
            details: $details,
        );
    }

    public function isOk(): bool
    {
        return $this->status === self::STATUS_OK;
    }

    public function isError(): bool
    {
        return !$this->isOk();
    }

    /**
     * What the error code of this ticket means. `null` when the ticket is `ok`.
     */
    public function classification(): ?ErrorClassification
    {
        if ($this->isOk()) {
            return null;
        }

        return $this->error?->classification() ?? ErrorClassification::Unknown;
    }

    /**
     * True only when Expo said `DeviceNotRegistered`. Delete that token.
     */
    public function invalidatesToken(): bool
    {
        return $this->error === PushError::DeviceNotRegistered;
    }

    /**
     * The receipt reference of this ticket, or null when there is nothing to look up.
     *
     * Only an accepted ticket with an ID produces a reference.
     */
    public function receiptReference(?int $notificationIndex = null, ?string $reference = null): ?ReceiptReference
    {
        if (!$this->isOk() || $this->id === null) {
            return null;
        }

        return new ReceiptReference($this->id, $this->token, $notificationIndex, $reference);
    }

    /**
     * The ticket in the shape that the SDK stores and reads back.
     *
     * This shape is not the Expo wire shape: it also holds the device token, and
     * `fromStorageArray()` gives that token back. Use `fromExpoArray()` for an
     * answer of the API.
     *
     * @return array<string, mixed>
     */
    public function toStorageArray(): array
    {
        return StorageEnvelope::wrap(self::STORAGE_TYPE, array_filter([
            'status' => $this->status,
            'id' => $this->id,
            'token' => $this->token?->value,
            'message' => $this->message,
            'errorCode' => $this->errorCode,
            'details' => $this->details === [] ? null : $this->details,
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

        $status = $data['status'] ?? null;

        if ($status !== self::STATUS_OK && $status !== self::STATUS_ERROR) {
            throw InvalidStorageException::missingField(self::STORAGE_TYPE, 'status');
        }

        $id = $data['id'] ?? null;

        // `fromExpoArray()` refuses an accepted ticket without an ID, and the
        // storage reader must refuse the same thing. Such a ticket would look
        // accepted and give nothing to look up.
        if ($status === self::STATUS_OK && (!is_string($id) || $id === '')) {
            throw new InvalidStorageException(sprintf(
                'The stored %s has the status "ok" and no receipt ID. An accepted ticket always holds one.',
                self::STORAGE_TYPE
            ));
        }

        $details = $data['details'] ?? [];

        if (!is_array($details)) {
            throw InvalidStorageException::missingField(self::STORAGE_TYPE, 'details');
        }

        // An error code of the wrong type would read as an unknown error, and
        // that turns a permanent rejection into work that waits for a fix.
        $errorCode = self::storedOptionalString($data, 'errorCode');

        /** @var array<string, mixed> $details */
        return new self(
            status: $status,
            id: is_string($id) ? $id : null,
            token: self::storedToken($data, 'token'),
            message: self::storedOptionalString($data, 'message'),
            error: $errorCode === null ? null : PushError::tryFrom($errorCode),
            errorCode: $errorCode,
            details: $details,
        );
    }

    /**
     * A stored token, checked before it becomes a `PushToken`.
     *
     * The reader of a stored array promises `InvalidStorageException`. A token
     * of the wrong shape must not escape as an `InvalidTokenException`.
     *
     * @param array<string, mixed> $data
     *
     * @throws InvalidStorageException
     */
    private static function storedToken(array $data, string $field): ?PushToken
    {
        $value = $data[$field] ?? null;

        if ($value === null) {
            return null;
        }

        if (!is_string($value) || !PushToken::isValid($value)) {
            throw InvalidStorageException::missingField(self::STORAGE_TYPE, $field);
        }

        return new PushToken($value);
    }

    /**
     * A present field of the wrong type is broken data, not an absent field.
     *
     * @param array<string, mixed> $data
     *
     * @throws InvalidStorageException
     */
    private static function storedOptionalString(array $data, string $field): ?string
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
     * The same shape as `toStorageArray()`.
     *
     * @return array<string, mixed>
     */
    #[\Override]
    public function jsonSerialize(): array
    {
        return $this->toStorageArray();
    }
}
