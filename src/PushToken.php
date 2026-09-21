<?php

declare(strict_types=1);

namespace Expo\Push;

use Expo\Push\Exception\InvalidTokenException;
use JsonSerializable;
use Stringable;

/**
 * A validated Expo push token.
 *
 * Accepts the bracket form, `ExponentPushToken[xxxxxxxx]` or `ExpoPushToken[xxxxxxxx]`,
 * and the bare UUID form that older clients return.
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
        $value = trim($value);

        if (!self::isValid($value)) {
            throw InvalidTokenException::for($value);
        }

        $this->value = $value;
    }

    /**
     * Returns the token, or null when the value is not an Expo push token.
     */
    #[\NoDiscard]
    public static function tryFrom(string $value): ?self
    {
        return self::isValid($value) ? new self($value) : null;
    }

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
