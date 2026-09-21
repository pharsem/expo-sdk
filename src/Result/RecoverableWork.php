<?php

declare(strict_types=1);

namespace Expo\Push\Result;

use Expo\Push\Exception\InvalidStorageException;
use Expo\Push\PushToken;
use Expo\Push\Storage\StorageEnvelope;
use JsonSerializable;

/**
 * The notifications of one send that still need a decision.
 *
 * Two groups, and they are not the same thing:
 *
 * - `notAttempted()`: the SDK never dispatched a request that held them. Sending
 *   them again cannot produce a duplicate.
 * - `ambiguous()`: a request that held them may already have reached Expo.
 *   Sending them again can show the notification twice on the device.
 *
 * The SDK never resends for you and never calls an ambiguous notification safe.
 * A new correlation value does not make a resend idempotent: Expo has no
 * idempotency key, and a collapse ID only replaces a notification that is still
 * on screen.
 */
final readonly class RecoverableWork implements JsonSerializable
{
    public const string STORAGE_TYPE = 'expo.recoverable_work';

    /**
     * @param list<NotificationOutcome> $outcomes             the unresolved notifications, in input order
     * @param int|null                  $earliestRetryAtUtcMs the first UTC moment at which a retry makes sense
     */
    public function __construct(
        public array $outcomes = [],
        public ?int $earliestRetryAtUtcMs = null,
    ) {
    }

    /**
     * Collects everything that a send left open: not attempted and unknown.
     */
    public static function fromSendResult(SendResult $result): self
    {
        $outcomes = [];

        foreach ($result->outcomes as $outcome) {
            if ($outcome->acceptance === Acceptance::NotAttempted || $outcome->acceptance === Acceptance::Unknown) {
                $outcomes[] = $outcome;
            }
        }

        return new self($outcomes, $result->earliestRetryAtUtcMs());
    }

    public function count(): int
    {
        return count($this->outcomes);
    }

    public function isEmpty(): bool
    {
        return $this->outcomes === [];
    }

    /**
     * The notifications that the SDK never dispatched. A resend cannot duplicate them.
     *
     * @return list<NotificationOutcome>
     */
    public function notAttempted(): array
    {
        return array_values(array_filter(
            $this->outcomes,
            static fn (NotificationOutcome $outcome): bool => $outcome->acceptance === Acceptance::NotAttempted
        ));
    }

    /**
     * The notifications that may already be on their way. A resend can duplicate them.
     *
     * @return list<NotificationOutcome>
     */
    public function ambiguous(): array
    {
        return array_values(array_filter(
            $this->outcomes,
            static fn (NotificationOutcome $outcome): bool => $outcome->acceptance === Acceptance::Unknown
                || $outcome->duplicateRisk
        ));
    }

    /**
     * Every device of the open work, without repeats.
     *
     * @return list<PushToken>
     */
    public function tokens(): array
    {
        $tokens = [];

        foreach ($this->outcomes as $outcome) {
            $tokens[$outcome->token->value] = $outcome->token;
        }

        return array_values($tokens);
    }

    /**
     * The correlation values of the open work, without repeats.
     *
     * @return list<string>
     */
    public function references(): array
    {
        $references = [];

        foreach ($this->outcomes as $outcome) {
            if ($outcome->reference !== null) {
                $references[$outcome->reference] = true;
            }
        }

        return array_keys($references);
    }

    /**
     * The exact input positions of the open work.
     *
     * @return list<int>
     */
    public function indexes(): array
    {
        return array_map(static fn (NotificationOutcome $outcome): int => $outcome->index, $this->outcomes);
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
            'notAttempted' => count($this->notAttempted()),
            'ambiguous' => count($this->ambiguous()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toStorageArray(): array
    {
        return StorageEnvelope::wrap(self::STORAGE_TYPE, [
            'outcomes' => array_map(
                static fn (NotificationOutcome $outcome): array => $outcome->toStorageArray(),
                $this->outcomes
            ),
            'earliestRetryAtUtcMs' => $this->earliestRetryAtUtcMs,
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
            outcomes: array_map(
                static fn (array $entry): NotificationOutcome => NotificationOutcome::fromStorageArray($entry),
                StorageEnvelope::listOfArrays(self::STORAGE_TYPE, $data, 'outcomes')
            ),
            earliestRetryAtUtcMs: is_int($data['earliestRetryAtUtcMs'] ?? null)
                ? (int) $data['earliestRetryAtUtcMs']
                : null,
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
