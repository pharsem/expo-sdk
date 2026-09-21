<?php

declare(strict_types=1);

namespace Expo\Push\Exception;

use InvalidArgumentException;

/**
 * A stored array does not match what the SDK writes.
 *
 * The SDK raises this for a missing envelope, an unknown schema version, a wrong
 * type name, or a field of the wrong type. The SDK holds no migration framework:
 * read the version, and write your own migration when you need one.
 */
final class InvalidStorageException extends InvalidArgumentException implements ExpoException
{
    public static function unsupportedVersion(string $type, int $version, int $supported): self
    {
        return new self(sprintf(
            'The stored %s uses schema version %d. This SDK reads version %d.',
            $type,
            $version,
            $supported
        ));
    }

    public static function wrongType(string $expected, string $found): self
    {
        return new self(sprintf('The stored array holds a %s, not a %s.', $found, $expected));
    }

    public static function missingField(string $type, string $field): self
    {
        return new self(sprintf('The stored %s has no valid "%s" field.', $type, $field));
    }
}
