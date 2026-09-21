<?php

declare(strict_types=1);

namespace Expo\Push\Exception;

use InvalidArgumentException;

/**
 * The SDK is set up in a way that cannot work.
 *
 * The constructor of `Expo` raises this before any request goes out. Examples: a
 * concurrency above what the transport supports, a retry budget below one attempt,
 * a rate limiter that is too small for the chunk size, or a hard deadline on a
 * transport that cannot interrupt a request.
 */
final class InvalidConfigurationException extends InvalidArgumentException implements ExpoException
{
}
