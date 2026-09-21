<?php

declare(strict_types=1);

namespace Expo\Push;

use Expo\Push\Exception\InvalidStorageException;
use Expo\Push\Storage\StorageEnvelope;
use JsonSerializable;

/**
 * The handoff result for one notification.
 *
 * A receipt with the status `ok` means that Apple or Google took the notification.
 * It does not mean that the device showed it. There is no third milestone in the
 * Expo API: device display is not reported.
 *
 * Expo keeps a receipt for a limited time and removes it after that, so a missing
 * receipt is not always a pending receipt.
 */
final readonly class PushReceipt implements JsonSerializable
{
    public const string STATUS_OK = 'ok';

    public const string STATUS_ERROR = 'error';

    public const string STORAGE_TYPE = 'expo.receipt';

    /**
     * @param string               $id        the receipt ID from the ticket
     * @param string               $status    `ok` or `error`
     * @param PushToken|null       $token     the device, when the SDK can map it
     * @param string|null          $message   the error text of Expo
     * @param PushError|null       $error     the typed error code, when the SDK knows it
     * @param string|null          $errorCode the raw error code of Expo, known or not
     * @param array<string, mixed> $details   the details object of Expo, kept as it arrived
     */
    public function __construct(
        public string $id,
        public string $status,
        public ?PushToken $token = null,
        public ?string $message = null,
        public ?PushError $error = null,
        public ?string $errorCode = null,
        public array $details = [],
    ) {
    }

    /**
     * Reads one entry of the `data` object of the receipt endpoint.
     *
     * Returns null when the entry is not a valid receipt. The caller then marks
     * that ID malformed, and keeps every other ID of the same answer.
     *
     * @param array<string, mixed> $data
     */
    public static function fromExpoArray(string $id, array $data, ?PushToken $token = null): ?self
    {
        $status = $data['status'] ?? null;

        if ($status !== self::STATUS_OK && $status !== self::STATUS_ERROR) {
            return null;
        }

        $details = isset($data['details']) && is_array($data['details']) ? $data['details'] : [];
        /** @var array<string, mixed> $details */
        $errorCode = isset($details['error']) && is_string($details['error']) ? $details['error'] : null;

        if ($token === null && isset($details['expoPushToken']) && is_string($details['expoPushToken'])) {
            $token = PushToken::tryFrom($details['expoPushToken']);
        }

        return new self(
            id: $id,
            status: $status,
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
     * What the error code of this receipt means. `null` when the receipt is `ok`.
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
     * A copy with a device token, for a lookup that starts from raw IDs.
     */
    public function withToken(PushToken $token): self
    {
        return new self($this->id, $this->status, $token, $this->message, $this->error, $this->errorCode, $this->details);
    }

    /**
     * The receipt in the shape that the SDK stores and reads back.
     *
     * @return array<string, mixed>
     */
    public function toStorageArray(): array
    {
        return StorageEnvelope::wrap(self::STORAGE_TYPE, array_filter([
            'id' => $this->id,
            'status' => $this->status,
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

        $id = $data['id'] ?? null;
        $status = $data['status'] ?? null;

        if (!is_string($id) || $id === '') {
            throw InvalidStorageException::missingField(self::STORAGE_TYPE, 'id');
        }

        if ($status !== self::STATUS_OK && $status !== self::STATUS_ERROR) {
            throw InvalidStorageException::missingField(self::STORAGE_TYPE, 'status');
        }

        $token = $data['token'] ?? null;
        $message = $data['message'] ?? null;
        $errorCode = $data['errorCode'] ?? null;
        $details = $data['details'] ?? [];

        if (!is_array($details)) {
            throw InvalidStorageException::missingField(self::STORAGE_TYPE, 'details');
        }

        /** @var array<string, mixed> $details */
        return new self(
            id: $id,
            status: $status,
            token: is_string($token) ? new PushToken($token) : null,
            message: is_string($message) ? $message : null,
            error: is_string($errorCode) ? PushError::tryFrom($errorCode) : null,
            errorCode: is_string($errorCode) ? $errorCode : null,
            details: $details,
        );
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
