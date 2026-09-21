<?php

declare(strict_types=1);

namespace Expo\Push\Protocol;

use Expo\Push\Support\Redact;

/**
 * One entry of the top level `errors` array of an Expo answer.
 *
 * These errors talk about the whole request, not about one device. Examples:
 * `PUSH_TOO_MANY_NOTIFICATIONS`, `PUSH_TOO_MANY_EXPERIENCE_IDS`, `UNAUTHORIZED`
 * and `TOO_MANY_REQUESTS`.
 *
 * The message and the details come from Expo and can hold a push token, so they
 * are not safe log fields. Use `summary()` for a log line.
 */
final readonly class ExpoApiError
{
    /**
     * @param array<string, mixed> $details
     */
    public function __construct(
        public ?string $code,
        public ?string $message,
        public array $details = [],
    ) {
    }

    /**
     * @param array<string, mixed> $entry
     */
    public static function fromArray(array $entry): self
    {
        $details = isset($entry['details']) && is_array($entry['details']) ? $entry['details'] : [];

        /** @var array<string, mixed> $details */
        return new self(
            code: isset($entry['code']) && is_string($entry['code']) ? $entry['code'] : null,
            message: isset($entry['message']) && is_string($entry['message']) ? $entry['message'] : null,
            details: $details,
        );
    }

    /**
     * A redacted one line form for a log or an observer.
     */
    public function summary(): string
    {
        return sprintf('%s: %s', $this->code ?? 'unknown', Redact::text($this->message) ?? '(no message)');
    }

    /**
     * @return array<string, mixed>
     */
    public function toStorageArray(): array
    {
        return array_filter([
            'code' => $this->code,
            'message' => $this->message,
            'details' => $this->details === [] ? null : $this->details,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * @param array<string, mixed> $stored
     */
    public static function fromStorageArray(array $stored): self
    {
        return self::fromArray($stored);
    }
}
