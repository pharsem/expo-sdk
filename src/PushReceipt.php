<?php

declare(strict_types=1);

namespace Expo\Push;

use JsonSerializable;

/**
 * The delivery result for one notification.
 *
 * Expo keeps a receipt for 24 hours. Read it some minutes after the send, then
 * delete every token with the error `DeviceNotRegistered`.
 */
final readonly class PushReceipt implements JsonSerializable
{
    /**
     * @param string               $id        the receipt ID from the ticket
     * @param string               $status    `ok` or `error`
     * @param PushToken|null       $token     the device, when the SDK can map it
     * @param string|null          $message   the error text of Expo
     * @param PushError|null       $error     the error code, when the SDK knows it
     * @param string|null          $errorCode the raw error code of Expo
     * @param array<string, mixed> $details   the details object of Expo
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
     * @param array<string, mixed> $data one entry of the `data` object of the API
     */
    public static function fromArray(string $id, array $data, ?PushToken $token = null): self
    {
        $details = isset($data['details']) && is_array($data['details']) ? $data['details'] : [];
        $errorCode = isset($details['error']) && is_string($details['error']) ? $details['error'] : null;

        if ($token === null && isset($details['expoPushToken']) && is_string($details['expoPushToken'])) {
            $token = PushToken::tryFrom($details['expoPushToken']);
        }

        /** @var array<string, mixed> $details */
        return new self(
            id: $id,
            status: isset($data['status']) && is_string($data['status']) ? $data['status'] : 'error',
            token: $token,
            message: isset($data['message']) && is_string($data['message']) ? $data['message'] : null,
            error: $errorCode === null ? null : PushError::tryFrom($errorCode),
            errorCode: $errorCode,
            details: $details,
        );
    }

    public function isOk(): bool
    {
        return $this->status === 'ok';
    }

    public function isError(): bool
    {
        return !$this->isOk();
    }

    /**
     * True when the device no longer accepts notifications. Delete the token.
     */
    public function isDeviceNotRegistered(): bool
    {
        return $this->error === PushError::DeviceNotRegistered;
    }

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function jsonSerialize(): array
    {
        return array_filter([
            'id' => $this->id,
            'status' => $this->status,
            'token' => $this->token?->value,
            'message' => $this->message,
            'details' => $this->details === [] ? null : $this->details,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
