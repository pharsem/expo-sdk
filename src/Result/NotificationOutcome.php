<?php

declare(strict_types=1);

namespace Expo\Push\Result;

use Expo\Push\Exception\InvalidStorageException;
use Expo\Push\PushTicket;
use Expo\Push\PushToken;
use Expo\Push\Storage\StorageEnvelope;
use JsonSerializable;

/**
 * The one outcome of one notification.
 *
 * There is exactly one outcome for each message and device pair of the input,
 * and the outcomes keep the input order whatever order the requests finished in.
 */
final readonly class NotificationOutcome implements JsonSerializable
{
    public const string STORAGE_TYPE = 'expo.notification_outcome';

    /**
     * @param int             $index          the stable position in the operation, from 0
     * @param int|string      $messageKey     the key or the ordinal of the message in your input
     * @param int             $recipientIndex the position of the device inside the message
     * @param PushToken       $token          the device
     * @param Acceptance      $acceptance     what the SDK knows about acceptance
     * @param PushTicket|null $ticket         the real ticket, when Expo sent one for this position
     * @param NotAcceptedReason|null $reason  why the acceptance is `NotAccepted`
     * @param bool            $duplicateRisk  true when an earlier ambiguous attempt can already have been accepted
     * @param string|null     $reference      your own correlation value from the message
     * @param int|null        $failureIndex   the position in `SendResult::requestFailures()`
     * @param int|null        $earliestRetryAtUtcMs the first UTC moment at which a retry makes sense
     * @param string|null     $detail         a short redacted explanation
     */
    public function __construct(
        public int $index,
        public int|string $messageKey,
        public int $recipientIndex,
        public PushToken $token,
        public Acceptance $acceptance,
        public ?PushTicket $ticket = null,
        public ?NotAcceptedReason $reason = null,
        public bool $duplicateRisk = false,
        public ?string $reference = null,
        public ?int $failureIndex = null,
        public ?int $earliestRetryAtUtcMs = null,
        public ?string $detail = null,
    ) {
    }

    public function isAccepted(): bool
    {
        return $this->acceptance === Acceptance::Accepted;
    }

    public function isUnknown(): bool
    {
        return $this->acceptance === Acceptance::Unknown;
    }

    public function wasAttempted(): bool
    {
        return $this->acceptance !== Acceptance::NotAttempted;
    }

    /**
     * The receipt ID of an accepted notification, or null.
     */
    public function receiptId(): ?string
    {
        return $this->isAccepted() ? $this->ticket?->id : null;
    }

    /**
     * The receipt reference of an accepted notification, or null.
     */
    public function receiptReference(): ?ReceiptReference
    {
        return $this->ticket?->receiptReference($this->index, $this->reference);
    }

    /**
     * @return array<string, mixed>
     */
    public function toStorageArray(): array
    {
        return StorageEnvelope::wrap(self::STORAGE_TYPE, [
            'index' => $this->index,
            'messageKey' => $this->messageKey,
            'recipientIndex' => $this->recipientIndex,
            'token' => $this->token->value,
            'acceptance' => $this->acceptance->value,
            'ticket' => $this->ticket?->toStorageArray(),
            'reason' => $this->reason?->value,
            'duplicateRisk' => $this->duplicateRisk,
            'reference' => $this->reference,
            'failureIndex' => $this->failureIndex,
            'earliestRetryAtUtcMs' => $this->earliestRetryAtUtcMs,
            'detail' => $this->detail,
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

        $token = $data['token'] ?? null;
        $acceptance = is_string($data['acceptance'] ?? null)
            ? Acceptance::tryFrom((string) $data['acceptance'])
            : null;

        if (!is_string($token) || $token === '') {
            throw InvalidStorageException::missingField(self::STORAGE_TYPE, 'token');
        }

        if ($acceptance === null) {
            throw InvalidStorageException::missingField(self::STORAGE_TYPE, 'acceptance');
        }

        $key = $data['messageKey'] ?? 0;
        $ticket = $data['ticket'] ?? null;
        $reason = $data['reason'] ?? null;

        return new self(
            index: is_int($data['index'] ?? null) ? (int) $data['index'] : 0,
            messageKey: is_int($key) || is_string($key) ? $key : 0,
            recipientIndex: is_int($data['recipientIndex'] ?? null) ? (int) $data['recipientIndex'] : 0,
            token: new PushToken($token),
            acceptance: $acceptance,
            ticket: is_array($ticket) ? PushTicket::fromStorageArray($ticket) : null,
            reason: is_string($reason) ? NotAcceptedReason::tryFrom($reason) : null,
            duplicateRisk: ($data['duplicateRisk'] ?? false) === true,
            reference: is_string($data['reference'] ?? null) ? (string) $data['reference'] : null,
            failureIndex: is_int($data['failureIndex'] ?? null) ? (int) $data['failureIndex'] : null,
            earliestRetryAtUtcMs: is_int($data['earliestRetryAtUtcMs'] ?? null)
                ? (int) $data['earliestRetryAtUtcMs']
                : null,
            detail: is_string($data['detail'] ?? null) ? (string) $data['detail'] : null,
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
