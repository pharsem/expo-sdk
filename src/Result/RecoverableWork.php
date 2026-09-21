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
 * The work holds every unresolved notification, whatever its acceptance. A
 * notification that Expo is known not to have accepted still belongs here when
 * the failure behind it can pass later: a 429 with a `Retry-After` header, a
 * deferred chunk, a chunk that an earlier failure stopped.
 *
 * Read the work through two questions, and keep them apart:
 *
 * Can a repeat duplicate the notification?
 *
 * - `notAttempted()`: the SDK never dispatched a request that held them. Sending
 *   them again cannot produce a duplicate.
 * - `ambiguous()`: a request that held them may already have reached Expo.
 *   Sending them again can show the notification twice on the device.
 *
 * May a repeat go out at all?
 *
 * - `retryable()`: the failure can pass on a later attempt. Wait until
 *   `earliestRetryAtUtcMs`, then decide. `dueAt()` gives the ones whose moment
 *   has come.
 * - `needsIntervention()`: fix the cause first. A credential failure, a limiter
 *   that broke, and an answer that the SDK cannot trust all land here.
 *
 * An accepted notification never enters the work, and a rejection that a repeat
 * cannot fix never enters it either.
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
     * Collects everything that a send left open.
     *
     * The selection reads the recovery disposition of each outcome, not the
     * acceptance alone. A notification that Expo refused with a 429 is still
     * open work, and an accepted one never is.
     */
    public static function fromSendResult(SendResult $result): self
    {
        $outcomes = [];

        foreach ($result->outcomes as $outcome) {
            if ($outcome->isOpen()) {
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
     * The notifications whose failure can pass on a later attempt.
     *
     * Eligible is not the same as due. Read `earliestRetryAtUtcMs` on each
     * outcome, or use `dueAt()`.
     *
     * @return list<NotificationOutcome>
     */
    public function retryable(): array
    {
        return array_values(array_filter(
            $this->outcomes,
            static fn (NotificationOutcome $outcome): bool => $outcome->isRetryable()
        ));
    }

    /**
     * The notifications that need an application decision before a repeat.
     *
     * @return list<NotificationOutcome>
     */
    public function needsIntervention(): array
    {
        return array_values(array_filter(
            $this->outcomes,
            static fn (NotificationOutcome $outcome): bool => $outcome->needsIntervention()
        ));
    }

    /**
     * The retryable notifications whose earliest retry time has come.
     *
     * @param int $nowUtcMillis the current UTC wall time in milliseconds
     *
     * @return list<NotificationOutcome>
     */
    public function dueAt(int $nowUtcMillis): array
    {
        return array_values(array_filter(
            $this->outcomes,
            static fn (NotificationOutcome $outcome): bool => $outcome->isDueAt($nowUtcMillis)
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
            'retryable' => count($this->retryable()),
            'needsIntervention' => count($this->needsIntervention()),
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
