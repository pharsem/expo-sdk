<?php

declare(strict_types=1);

namespace Expo\Push\Result;

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
     * @param array<string, mixed> $stored
     */
    public static function fromStorageArray(array $stored): self
    {
        $result = $stored['result'] ?? null;
        $transmission = $stored['transmission'] ?? null;

        return new self(
            number: is_int($stored['number'] ?? null) ? (int) $stored['number'] : 1,
            result: (is_string($result) ? AttemptResult::tryFrom($result) : null) ?? AttemptResult::TransportFailure,
            status: is_int($stored['status'] ?? null) ? (int) $stored['status'] : null,
            code: is_string($stored['code'] ?? null) ? (string) $stored['code'] : null,
            transmission: (is_string($transmission) ? Transmission::tryFrom($transmission) : null)
                ?? Transmission::Unknown,
            durationMs: is_int($stored['durationMs'] ?? null) ? (int) $stored['durationMs'] : null,
            startedAtUtcMs: is_int($stored['startedAtUtcMs'] ?? null) ? (int) $stored['startedAtUtcMs'] : null,
            summary: is_string($stored['summary'] ?? null) ? (string) $stored['summary'] : null,
            serverDelayMs: is_int($stored['serverDelayMs'] ?? null) ? (int) $stored['serverDelayMs'] : null,
        );
    }
}
