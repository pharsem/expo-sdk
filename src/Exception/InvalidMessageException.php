<?php

declare(strict_types=1);

namespace Expo\Push\Exception;

use InvalidArgumentException;

/**
 * The message is not valid, so the SDK does not send it.
 */
class InvalidMessageException extends InvalidArgumentException implements ExpoException
{
}
