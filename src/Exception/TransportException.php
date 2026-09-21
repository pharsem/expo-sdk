<?php

declare(strict_types=1);

namespace Expo\Push\Exception;

use RuntimeException;

/**
 * The SDK could not reach Expo, or Expo sent a body that is not valid JSON.
 */
final class TransportException extends RuntimeException implements ExpoException
{
}
