<?php

declare(strict_types=1);

namespace Expo\Push\Exception;

use InvalidArgumentException;

/**
 * The value is not an Expo push token.
 */
final class InvalidTokenException extends InvalidArgumentException implements ExpoException
{
    public static function for(string $value): self
    {
        return new self(sprintf(
            '"%s" is not an Expo push token. A token looks like ExponentPushToken[xxxxxxxxxxxxxxxxxxxxxx].',
            $value
        ));
    }
}
