<?php

declare(strict_types=1);

namespace Expo\Push\Result;

use Expo\Push\Exception\InvalidStorageException;
use Expo\Push\Http\Transmission;
use Expo\Push\Retry\AttemptResult;

/**
 * One attempt of one chunk, kept for the result and for your logs.
 *
 * Every field here is safe to log. No token, no body and no credential reaches it.
 */
final readonly class AttemptRecord
{
    /**
     * The name that a storage error message uses. An attempt has no envelope of
     * its own: it always sits inside a request failure.
     */
    public const string STORAGE_TYPE = 'expo.attempt_record';

    /**
     * @param int          $number        the attempt number, from 1
     * @param AttemptResult $result       what the attempt produced
     * @param int|null     $status        the HTTP status, when there was an answer
     * @param string|null  $code          the cURL error name, or the protocol failure kind
     * @param Transmission $transmission  what the SDK knows about the request bytes
     * @param int|null     $durationMs    how long the attempt took
     * @param int|null     $startedAtUtcMs the UTC wall time when the attempt started
     * @param string|null  $summary       a short redacted explanation
     * @param int|null     $serverDelayMs the `Retry-After` value that the server sent
     */
    public function __construct(
        public int $number,
        public AttemptResult $result,
        public ?int $status = null,
        public ?string $code = null,
        public Transmission $transmission = Transmission::Unknown,
        public ?int $durationMs = null,
        public ?int $startedAtUtcMs = null,
        public ?string $summary = null,
        public ?int $serverDelayMs = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toStorageArray(): array
    {
        return array_filter([
            'number' => $this->number,
            'result' => $this->result->value,
            'status' => $this->status,
            'code' => $this->code,
            'transmission' => $this->transmission->value,
            'durationMs' => $this->durationMs,
            'startedAtUtcMs' => $this->startedAtUtcMs,
            'summary' => $this->summary,
            'serverDelayMs' => $this->serverDelayMs,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * Reads one stored attempt.
     *
     * `toStorageArray()` always writes the number, the result and the
     * transmission, so the reader demands all three. A broken value never
     * becomes attempt 1 of a transport failure that nobody made.
     *
     * @param array<string, mixed> $stored
     *
     * @throws InvalidStorageException
     */
    public static function fromStorageArray(array $stored): self
    {
        $number = $stored['number'] ?? null;

        if (!is_int($number) || $number < 1) {
            throw InvalidStorageException::missingField(self::STORAGE_TYPE, 'number');
        }

        $result = $stored['result'] ?? null;
        $transmission = $stored['transmission'] ?? null;

        if (!is_string($result) || AttemptResult::tryFrom($result) === null) {
            throw InvalidStorageException::missingField(self::STORAGE_TYPE, 'result');
        }

        if (!is_string($transmission) || Transmission::tryFrom($transmission) === null) {
            throw InvalidStorageException::missingField(self::STORAGE_TYPE, 'transmission');
        }

        return new self(
            number: $number,
            result: AttemptResult::from($result),
            status: self::optionalInt($stored, 'status'),
            code: self::optionalString($stored, 'code'),
            transmission: Transmission::from($transmission),
            durationMs: self::optionalInt($stored, 'durationMs'),
            startedAtUtcMs: self::optionalInt($stored, 'startedAtUtcMs'),
            summary: self::optionalString($stored, 'summary'),
            serverDelayMs: self::optionalInt($stored, 'serverDelayMs'),
        );
    }

    /**
     * @param array<string, mixed> $stored
     *
     * @throws InvalidStorageException
     */
    private static function optionalInt(array $stored, string $field): ?int
    {
        $value = $stored[$field] ?? null;

        if ($value === null) {
            return null;
        }

        if (!is_int($value)) {
            throw InvalidStorageException::missingField(self::STORAGE_TYPE, $field);
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $stored
     *
     * @throws InvalidStorageException
     */
    private static function optionalString(array $stored, string $field): ?string
    {
        $value = $stored[$field] ?? null;

        if ($value === null) {
            return null;
        }

        if (!is_string($value)) {
            throw InvalidStorageException::missingField(self::STORAGE_TYPE, $field);
        }

        return $value;
    }
}
