<?php

declare(strict_types=1);

namespace Expo\Push;

use JsonSerializable;

/**
 * The answer of Expo for one device.
 *
 * A ticket with the status `ok` means that Expo accepted the message. It does not
 * mean that the device got it. Check the receipt for the delivery result.
 */
final readonly class PushTicket implements JsonSerializable
{
    /**
     * @param string                $status    `ok` or `error`
     * @param string|null           $id        the receipt ID, present when the status is `ok`
     * @param PushToken|null        $token     the device that this ticket belongs to
     * @param string|null           $message   the error text of Expo
     * @param PushError|null        $error     the error code, when the SDK knows it
     * @param string|null           $errorCode the raw error code of Expo
     * @param array<string, mixed>  $details   the details object of Expo
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
     * @param array<string, mixed> $data one entry of the `data` array of the API
     */
    public static function fromArray(array $data, ?PushToken $token = null): self
    {
        $details = isset($data['details']) && is_array($data['details']) ? $data['details'] : [];
        $errorCode = isset($details['error']) && is_string($details['error']) ? $details['error'] : null;

        if ($token === null && isset($details['expoPushToken']) && is_string($details['expoPushToken'])) {
            $token = PushToken::tryFrom($details['expoPushToken']);
        }

        /** @var array<string, mixed> $details */
        return new self(
            status: isset($data['status']) && is_string($data['status']) ? $data['status'] : 'error',
            id: isset($data['id']) && is_string($data['id']) ? $data['id'] : null,
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
            'status' => $this->status,
            'id' => $this->id,
            'token' => $this->token?->value,
            'message' => $this->message,
            'details' => $this->details === [] ? null : $this->details,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
