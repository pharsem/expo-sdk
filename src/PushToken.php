<?php

declare(strict_types=1);

namespace Expo\Push;

use Expo\Push\Exception\InvalidTokenException;
use Expo\Push\Support\Redact;
use JsonSerializable;
use Stringable;

/**
 * A validated Expo push token.
 *
 * The class accepts the bracket form, `ExponentPushToken[xxxxxxxx]` or
 * `ExpoPushToken[xxxxxxxx]`, and the bare UUID form that older clients return.
 *
 * Two rules that never change:
 *
 * - The SDK trims the leading and trailing whitespace and keeps everything else.
 *   The value inside the brackets is opaque, so the SDK never changes its case.
 * - A valid shape says nothing about registration. Only a ticket or a receipt
 *   with `DeviceNotRegistered` tells you that a device is gone.
 */
final readonly class PushToken implements JsonSerializable, Stringable
{
    private const string PATTERN = '/^(?:Expo(?:nent)?PushToken\[[^\]\s]+\]|[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})$/i';

    public string $value;

    /**
     * @throws InvalidTokenException when the value is not an Expo push token
     */
    public function __construct(string $value)
    {
        $trimmed = trim($value);

        if (!self::isValid($trimmed)) {
            throw InvalidTokenException::for($trimmed);
        }

        $this->value = $trimmed;
    }

    /**
     * Returns the token, or null when the value is not an Expo push token.
     */
    #[\NoDiscard]
    public static function tryFrom(string $value): ?self
    {
        return self::isValid($value) ? new self($value) : null;
    }

    /**
     * True when the value has the shape of an Expo push token.
     *
     * The check trims the whitespace first, exactly like the constructor.
     */
    public static function isValid(string $value): bool
    {
        return preg_match(self::PATTERN, trim($value)) === 1;
    }

    /**
     * Accepts a token object or a raw string.
     */
    #[\NoDiscard]
    public static function from(self|string $value): self
    {
        return $value instanceof self ? $value : new self($value);
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    /**
     * A short stable value for a log line. It cannot give the token back.
     */
    public function fingerprint(): string
    {
        return Redact::token($this->value);
    }

    #[\Override]
    public function jsonSerialize(): string
    {
        return $this->value;
    }

    #[\Override]
    public function __toString(): string
    {
        return $this->value;
    }
}
