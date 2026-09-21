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
 *
 * Three fields answer three different questions, and none of them replaces
 * another:
 *
 * - `acceptance`: what Expo did with this notification.
 * - `recovery`: whether the work is still open, and whether a repeat needs an
 *   application decision first.
 * - `duplicateRisk`: whether a repeat can show the notification twice.
 */
final readonly class NotificationOutcome implements JsonSerializable
{
    public const string STORAGE_TYPE = 'expo.notification_outcome';

    /**
     * What an application may still do with this notification.
     */
    public RecoveryDisposition $recovery;

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
     * @param RecoveryDisposition|null $recovery what an application may still do. Null derives the careful value
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
        ?RecoveryDisposition $recovery = null,
    ) {
        // An accepted notification is never automatic resend work, whatever the
        // caller asks for. Everything else keeps the given value, and a missing
        // value becomes the careful one.
        $this->recovery = $acceptance === Acceptance::Accepted
            ? RecoveryDisposition::None
            : ($recovery ?? self::carefulRecovery($acceptance, $ticket));
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
     * True when this notification still needs a decision from you.
     */
    public function isOpen(): bool
    {
        return $this->recovery->isOpen();
    }

    /**
     * True when the failure behind this outcome can pass on a later attempt.
     *
     * This is not permission to send now. Read `earliestRetryAtUtcMs` for the
     * moment, and `duplicateRisk` for the price.
     */
    public function isRetryable(): bool
    {
        return $this->recovery === RecoveryDisposition::Retryable;
    }

    /**
     * True when a repeat needs an application decision before it makes sense.
     */
    public function needsIntervention(): bool
    {
        return $this->recovery === RecoveryDisposition::NeedsIntervention;
    }

    /**
     * True when this notification may go out again at the given UTC moment.
     *
     * An open retryable outcome with no retry time is due at once.
     */
    public function isDueAt(int $nowUtcMillis): bool
    {
        if ($this->recovery !== RecoveryDisposition::Retryable) {
            return false;
        }

        return $this->earliestRetryAtUtcMs === null || $this->earliestRetryAtUtcMs <= $nowUtcMillis;
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
            'recovery' => $this->recovery->value,
        ]);
    }

    /**
     * Reads one stored outcome, and refuses anything that the SDK never wrote.
     *
     * The reader never invents an identity, never turns a broken value into a
     * zero, and never lets an accepted outcome through without the ticket that
     * proves it.
     *
     * One rule covers an older writer of the same schema version: an outcome
     * from SDK 2.0.0 holds no `recovery` field. The reader then derives the
     * careful value, which never claims that open work is closed.
     *
     * @param array<string, mixed> $stored
     *
     * @throws InvalidStorageException
     */
    public static function fromStorageArray(array $stored): self
    {
        $data = StorageEnvelope::unwrap(self::STORAGE_TYPE, $stored);

        $token = $data['token'] ?? null;

        if (!is_string($token) || $token === '') {
            throw InvalidStorageException::missingField(self::STORAGE_TYPE, 'token');
        }

        $acceptance = self::requiredEnum($data, 'acceptance', Acceptance::class);
        $index = self::requiredIndex($data, 'index');
        $recipientIndex = self::requiredIndex($data, 'recipientIndex');
        $messageKey = $data['messageKey'] ?? null;

        if (!is_int($messageKey) && !is_string($messageKey)) {
            throw InvalidStorageException::missingField(self::STORAGE_TYPE, 'messageKey');
        }

        // A missing flag would tell the application that a resend is safe. The
        // reader refuses instead of reassuring.
        $duplicateRisk = $data['duplicateRisk'] ?? null;

        if (!is_bool($duplicateRisk)) {
            throw InvalidStorageException::missingField(self::STORAGE_TYPE, 'duplicateRisk');
        }

        $ticket = self::readTicket($data);
        $reason = self::readReason($data);
        $recovery = array_key_exists('recovery', $data) && $data['recovery'] !== null
            ? self::requiredEnum($data, 'recovery', RecoveryDisposition::class)
            : null;

        $outcome = new self(
            index: $index,
            messageKey: $messageKey,
            recipientIndex: $recipientIndex,
            token: new PushToken($token),
            acceptance: $acceptance,
            ticket: $ticket,
            reason: $reason,
            duplicateRisk: $duplicateRisk,
            reference: self::optionalString($data, 'reference'),
            failureIndex: self::optionalIndex($data, 'failureIndex'),
            earliestRetryAtUtcMs: self::optionalInt($data, 'earliestRetryAtUtcMs'),
            detail: self::optionalString($data, 'detail'),
            recovery: $recovery,
        );

        $outcome->assertConsistent();

        return $outcome;
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
     * The disposition that the evidence alone supports.
     *
     * The rule never closes open work. An unresolved notification without an
     * explicit disposition asks the application to look at it.
     */
    private static function carefulRecovery(Acceptance $acceptance, ?PushTicket $ticket): RecoveryDisposition
    {
        if ($acceptance === Acceptance::Accepted) {
            return RecoveryDisposition::None;
        }

        // Only Expo itself closes a notification with a rejection, and only when
        // the code says that a later send cannot work.
        if (
            $acceptance === Acceptance::NotAccepted
            && $ticket !== null
            && $ticket->isError()
            && $ticket->classification()?->maySucceedLater() === false
        ) {
            return RecoveryDisposition::None;
        }

        return RecoveryDisposition::NeedsIntervention;
    }

    /**
     * Refuses a stored combination that no send can produce.
     *
     * @throws InvalidStorageException
     */
    private function assertConsistent(): void
    {
        if ($this->acceptance === Acceptance::Accepted) {
            if ($this->ticket === null || !$this->ticket->isOk() || $this->ticket->id === null) {
                throw new InvalidStorageException(sprintf(
                    'The stored %s is accepted and holds no successful ticket with a receipt ID. Acceptance needs '
                    . 'that evidence.',
                    self::STORAGE_TYPE
                ));
            }

            if ($this->reason !== null) {
                throw new InvalidStorageException(sprintf(
                    'The stored %s is accepted and names a rejection reason. The two contradict each other.',
                    self::STORAGE_TYPE
                ));
            }
        }

        if ($this->acceptance === Acceptance::NotAccepted && $this->reason === null) {
            throw InvalidStorageException::missingField(self::STORAGE_TYPE, 'reason');
        }

        if ($this->acceptance === Acceptance::NotAttempted) {
            if ($this->ticket !== null) {
                throw new InvalidStorageException(sprintf(
                    'The stored %s was never attempted and holds a ticket. Expo answers only what it received.',
                    self::STORAGE_TYPE
                ));
            }

            if ($this->duplicateRisk) {
                throw new InvalidStorageException(sprintf(
                    'The stored %s was never attempted and carries a duplicate risk. Nothing went out for it.',
                    self::STORAGE_TYPE
                ));
            }
        }

        $ticketToken = $this->ticket?->token;

        if ($ticketToken !== null && $ticketToken->value !== $this->token->value) {
            throw new InvalidStorageException(sprintf(
                'The stored %s holds a ticket of another device. The association is broken.',
                self::STORAGE_TYPE
            ));
        }
    }

    /**
     * @param array<string, mixed> $data
     *
     * @throws InvalidStorageException
     */
    private static function readTicket(array $data): ?PushTicket
    {
        $ticket = $data['ticket'] ?? null;

        if ($ticket === null) {
            return null;
        }

        if (!is_array($ticket)) {
            throw InvalidStorageException::missingField(self::STORAGE_TYPE, 'ticket');
        }

        /** @var array<string, mixed> $ticket */
        return PushTicket::fromStorageArray($ticket);
    }

    /**
     * @param array<string, mixed> $data
     *
     * @throws InvalidStorageException
     */
    private static function readReason(array $data): ?NotAcceptedReason
    {
        $reason = $data['reason'] ?? null;

        if ($reason === null) {
            return null;
        }

        if (!is_string($reason)) {
            throw InvalidStorageException::missingField(self::STORAGE_TYPE, 'reason');
        }

        // The reason is an SDK state, not an extensible provider code. An
        // unknown value means that the array does not come from this SDK.
        return NotAcceptedReason::tryFrom($reason)
            ?? throw InvalidStorageException::missingField(self::STORAGE_TYPE, 'reason');
    }

    /**
     * @template T of \BackedEnum
     *
     * @param array<string, mixed> $data
     * @param class-string<T>      $enum
     *
     * @return T
     *
     * @throws InvalidStorageException
     */
    private static function requiredEnum(array $data, string $field, string $enum): \BackedEnum
    {
        $value = $data[$field] ?? null;

        if (!is_string($value)) {
            throw InvalidStorageException::missingField(self::STORAGE_TYPE, $field);
        }

        return $enum::tryFrom($value) ?? throw InvalidStorageException::missingField(self::STORAGE_TYPE, $field);
    }

    /**
     * @param array<string, mixed> $data
     *
     * @throws InvalidStorageException
     */
    private static function requiredIndex(array $data, string $field): int
    {
        $value = $data[$field] ?? null;

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
    private static function optionalInt(array $data, string $field): ?int
    {
        $value = $data[$field] ?? null;

        if ($value === null) {
            return null;
        }

        if (!is_int($value)) {
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
}
