<?php

declare(strict_types=1);

namespace Expo\Push;

use Expo\Push\Exception\InvalidMessageException;
use JsonSerializable;

/**
 * The sound that iOS plays for a notification.
 *
 * Use `Sound::default()` for the standard sound, `Sound::named()` for a bundled
 * file, `Sound::none()` for a silent notification, and `Sound::critical()` for a
 * critical alert. A critical alert needs the critical alert entitlement from Apple.
 */
final readonly class Sound implements JsonSerializable
{
    private function __construct(
        public ?string $name,
        public bool $critical = false,
        public ?float $volume = null,
    ) {
    }

    #[\NoDiscard]
    public static function default(): self
    {
        return new self('default');
    }

    /**
     * @param string $name the file name of a sound in the app bundle, such as `bells.wav`
     */
    #[\NoDiscard]
    public static function named(string $name): self
    {
        if ($name === '') {
            throw new InvalidMessageException('The sound name must not be empty.');
        }

        return new self($name);
    }

    /**
     * A notification that plays no sound.
     */
    #[\NoDiscard]
    public static function none(): self
    {
        return new self(null);
    }

    /**
     * A critical alert. It plays even when the device is in Do Not Disturb mode.
     *
     * @param float $volume a value from 0.0 to 1.0
     */
    #[\NoDiscard]
    public static function critical(string $name = 'default', float $volume = 1.0): self
    {
        if ($volume < 0.0 || $volume > 1.0) {
            throw new InvalidMessageException(
                sprintf('The sound volume must be between 0.0 and 1.0, got %s.', $volume)
            );
        }

        return new self($name, true, $volume);
    }

    /**
     * Accepts a sound object or the name of a sound.
     */
    #[\NoDiscard]
    public static function from(self|string $value): self
    {
        return $value instanceof self ? $value : self::named($value);
    }

    /**
     * @return string|array{critical: bool, name: string|null, volume: float}|null
     */
    #[\Override]
    public function jsonSerialize(): string|array|null
    {
        if ($this->critical) {
            return [
                'critical' => true,
                'name' => $this->name,
                'volume' => $this->volume ?? 1.0,
            ];
        }

        return $this->name;
    }
}
