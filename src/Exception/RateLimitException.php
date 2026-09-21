<?php

declare(strict_types=1);

namespace Expo\Push\Exception;

/**
 * Expo rate limited the request. The limit is 600 notifications per second.
 */
final class RateLimitException extends ExpoApiException
{
}
