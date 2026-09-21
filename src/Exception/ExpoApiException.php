<?php

declare(strict_types=1);

namespace Expo\Push\Exception;

use RuntimeException;

/**
 * Expo rejected the whole request.
 *
 * A single message that fails does not raise this exception. It becomes an error
 * ticket in the result instead.
 */
class ExpoApiException extends RuntimeException implements ExpoException
{
    /**
     * @param string|null                                                 $errorCode the Expo code, such as `PUSH_TOO_MANY_NOTIFICATIONS`
     * @param int                                                         $status    the HTTP status code
     * @param list<array{code?: string, message?: string, details?: mixed}> $errors   every error that Expo returned
     */
    public function __construct(
        string $message,
        public readonly ?string $errorCode = null,
        public readonly int $status = 0,
        public readonly array $errors = [],
    ) {
        parent::__construct($message);
    }

    /**
     * @param list<array{code?: string, message?: string, details?: mixed}> $errors
     */
    public static function fromErrors(array $errors, int $status): self
    {
        $first = $errors[0] ?? [];
        $code = isset($first['code']) && is_string($first['code']) ? $first['code'] : null;
        $message = isset($first['message']) && is_string($first['message'])
            ? $first['message']
            : sprintf('Expo rejected the request with status %d.', $status);

        if ($code === 'TOO_MANY_REQUESTS' || $status === 429) {
            return new RateLimitException($message, $code, $status, $errors);
        }

        return new self($message, $code, $status, $errors);
    }
}
