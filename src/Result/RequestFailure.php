<?php

declare(strict_types=1);

namespace Expo\Push\Result;

use Expo\Push\Exception\InvalidStorageException;
use Expo\Push\Protocol\ExpoApiError;
use Expo\Push\Storage\StorageEnvelope;
use Expo\Push\Support\Redact;
use JsonSerializable;

/**
 * One request that did not produce a usable answer.
 *
 * The object names the exact positions that the request held, so you can
 * schedule those and only those. It never holds a fake ticket.
 */
final readonly class RequestFailure implements JsonSerializable
{
    public const string STORAGE_TYPE = 'expo.request_failure';

    /**
     * The largest number of attempts that the object keeps.
     */
    public const int MAX_ATTEMPTS_KEPT = 10;

    /** @var list<AttemptRecord> */
    public array $attempts;

    /**
     * @param OperationType      $operation      send or receipts
     * @param int                $chunkOrdinal   the position of the chunk in the operation
     * @param list<int>          $indexes        the exact notification positions of a send
     * @param list<string>       $ids            the exact receipt IDs of a lookup
     * @param FailureCategory    $category       what kind of problem this is
     * @param string             $message        a redacted explanation
     * @param int|null           $httpStatus     the last HTTP status, when there was one
     * @param string|null        $transportCode  the last cURL error name or exception class
     * @param list<ExpoApiError> $expoErrors     the request level errors that Expo reported
     * @param list<AttemptRecord> $attempts      the attempt history, newest last
     * @param bool               $retryable      true when the failure itself can pass on a later attempt, by the default delivery rules
     * @param bool               $deferred       true when the SDK stopped to wait, not because it gave up
     * @param int|null           $earliestRetryAtUtcMs the first UTC moment at which a retry makes sense
     */
    public function __construct(
        public OperationType $operation,
        public int $chunkOrdinal,
        public array $indexes,
        public array $ids,
        public FailureCategory $category,
        public string $message,
        public ?int $httpStatus = null,
        public ?string $transportCode = null,
        public array $expoErrors = [],
        array $attempts = [],
        public bool $retryable = false,
        public bool $deferred = false,
        public ?int $earliestRetryAtUtcMs = null,
    ) {
        $this->attempts = count($attempts) > self::MAX_ATTEMPTS_KEPT
            ? array_slice($attempts, -self::MAX_ATTEMPTS_KEPT)
            : array_values($attempts);
    }

    /**
     * The number of notifications or IDs that this request held.
     */
    public function size(): int
    {
        return $this->operation === OperationType::Send ? count($this->indexes) : count($this->ids);
    }

    /**
     * The first and the last notification index of a send request.
     *
     * The range shows the real length of the chunk, including a last chunk that
     * is shorter than the others.
     *
     * @return array{0: int, 1: int}|null
     */
    public function indexRange(): ?array
    {
        if ($this->indexes === []) {
            return null;
        }

        return [$this->indexes[0], $this->indexes[count($this->indexes) - 1]];
    }

    public function attemptCount(): int
    {
        return count($this->attempts);
    }

    /**
     * A one line form for a log or an observer. It holds no token and no body.
     */
    public function summary(): string
    {
        return sprintf(
            '%s chunk %d: %s (%s), %d item(s), %d attempt(s)%s',
            $this->operation->value,
            $this->chunkOrdinal,
            Redact::text($this->message) ?? 'no detail',
            $this->category->value,
            $this->size(),
            $this->attemptCount(),
            $this->deferred ? ', deferred' : ''
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toStorageArray(): array
    {
        return StorageEnvelope::wrap(self::STORAGE_TYPE, [
            'operation' => $this->operation->value,
            'chunkOrdinal' => $this->chunkOrdinal,
            'indexes' => $this->indexes,
            'ids' => $this->ids,
            'category' => $this->category->value,
            'message' => $this->message,
            'httpStatus' => $this->httpStatus,
            'transportCode' => $this->transportCode,
            'expoErrors' => array_map(
                static fn (ExpoApiError $error): array => $error->toStorageArray(),
                $this->expoErrors
            ),
            'attempts' => array_map(
                static fn (AttemptRecord $attempt): array => $attempt->toStorageArray(),
                $this->attempts
            ),
            'retryable' => $this->retryable,
            'deferred' => $this->deferred,
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

        $operation = is_string($data['operation'] ?? null)
            ? OperationType::tryFrom((string) $data['operation'])
            : null;
        $category = is_string($data['category'] ?? null)
            ? FailureCategory::tryFrom((string) $data['category'])
            : null;

        if ($operation === null) {
            throw InvalidStorageException::missingField(self::STORAGE_TYPE, 'operation');
        }

        if ($category === null) {
            throw InvalidStorageException::missingField(self::STORAGE_TYPE, 'category');
        }

        $rawIndexes = $data['indexes'] ?? null;

        if (!is_array($rawIndexes) || !array_is_list($rawIndexes)) {
            throw InvalidStorageException::missingField(self::STORAGE_TYPE, 'indexes');
        }

        $indexes = [];

        foreach ($rawIndexes as $index) {
            if (!is_int($index) || $index < 0) {
                throw InvalidStorageException::missingField(self::STORAGE_TYPE, 'indexes');
            }

            $indexes[] = $index;
        }

        $ids = StorageEnvelope::listOfStrings(self::STORAGE_TYPE, $data, 'ids');
        $ordinal = $data['chunkOrdinal'] ?? null;
        $message = $data['message'] ?? null;

        if (!is_int($ordinal) || $ordinal < 0) {
            throw InvalidStorageException::missingField(self::STORAGE_TYPE, 'chunkOrdinal');
        }

        if (!is_string($message)) {
            throw InvalidStorageException::missingField(self::STORAGE_TYPE, 'message');
        }

        // A send failure names notification positions, and a lookup failure
        // names receipt IDs. A stored array that holds the other kind, or
        // neither kind, does not come from this SDK: every request of the SDK
        // holds at least one notification or one receipt ID.
        if ($operation === OperationType::Send && ($ids !== [] || $indexes === [])) {
            throw InvalidStorageException::missingField(self::STORAGE_TYPE, 'indexes');
        }

        if ($operation === OperationType::Receipts && ($indexes !== [] || $ids === [])) {
            throw InvalidStorageException::missingField(self::STORAGE_TYPE, 'ids');
        }

        return new self(
            operation: $operation,
            chunkOrdinal: $ordinal,
            indexes: $indexes,
            ids: $ids,
            category: $category,
            message: $message,
            // A present field of the wrong type is broken data, not an absent
            // field. A corrupted retry time must not read as "retry now".
            httpStatus: self::optionalInt($data, 'httpStatus'),
            transportCode: self::optionalString($data, 'transportCode'),
            expoErrors: array_map(
                static fn (array $entry): ExpoApiError => ExpoApiError::fromStorageArray($entry),
                StorageEnvelope::listOfArrays(self::STORAGE_TYPE, $data, 'expoErrors')
            ),
            attempts: array_map(
                static fn (array $entry): AttemptRecord => AttemptRecord::fromStorageArray($entry),
                StorageEnvelope::listOfArrays(self::STORAGE_TYPE, $data, 'attempts')
            ),
            // A missing flag would tell the application that nothing can pass
            // later, or that the SDK gave up when it only waited. The reader
            // refuses instead of guessing.
            retryable: self::requiredBool($data, 'retryable'),
            deferred: self::requiredBool($data, 'deferred'),
            earliestRetryAtUtcMs: self::optionalInt($data, 'earliestRetryAtUtcMs'),
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

    /**
     * @param array<string, mixed> $data
     *
     * @throws InvalidStorageException
     */
    private static function requiredBool(array $data, string $field): bool
    {
        $value = $data[$field] ?? null;

        if (!is_bool($value)) {
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
