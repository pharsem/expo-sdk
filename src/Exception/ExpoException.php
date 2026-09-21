<?php

declare(strict_types=1);

namespace Expo\Push\Exception;

use Throwable;

/**
 * Every exception of this package implements this interface.
 *
 * Catch `ExpoException` to catch all of them.
 */
interface ExpoException extends Throwable
{
}
