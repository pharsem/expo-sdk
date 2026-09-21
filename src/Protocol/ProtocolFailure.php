<?php

declare(strict_types=1);

namespace Expo\Push\Protocol;

use Expo\Push\Support\Redact;

/**
 * A 2xx answer that the SDK cannot use.
 */
final readonly class ProtocolFailure
{
    /**
     * @param ProtocolFailureKind $kind    why the answer is not usable
     * @param string              $message a redacted explanation
     * @param string|null         $snippet a short redacted part of the body, for your own diagnosis
     */
    public function __construct(
        public ProtocolFailureKind $kind,
        public string $message,
        public ?string $snippet = null,
    ) {
    }

    public static function of(ProtocolFailureKind $kind, string $message, ?string $body = null): self
    {
        return new self($kind, $message, $body === null ? null : Redact::text($body, 120));
    }

    public function summary(): string
    {
        return sprintf('%s: %s', $this->kind->value, $this->message);
    }
}
