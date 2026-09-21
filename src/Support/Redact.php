<?php

declare(strict_types=1);

namespace Expo\Push\Support;

use Throwable;

/**
 * Makes a value safe for a log line.
 *
 * The SDK gives an observer only redacted values. A push token, an authorization
 * header, a message body and a raw response body never reach the observer.
 *
 * A storage array is a different thing. It holds the real tokens on purpose, so
 * never send a storage array to a logger.
 */
final class Redact
{
    /**
     * The longest text that the SDK puts in a diagnostic field.
     */
    public const int MAX_TEXT = 200;

    /**
     * A short stable fingerprint of a token. The same token always gives the same
     * value, and the value cannot give the token back.
     */
    public static function token(string $token): string
    {
        return 'tok_' . substr(hash('sha256', $token), 0, 12);
    }

    /**
     * Cuts a text to `MAX_TEXT` bytes and removes anything that looks like a token
     * or a bearer credential.
     */
    public static function text(?string $text, int $max = self::MAX_TEXT): ?string
    {
        if ($text === null) {
            return null;
        }

        $clean = (string) preg_replace(
            [
                '/Expo(?:nent)?PushToken\[[^\]]*\]/i',
                '/\bBearer\s+\S+/i',
                '/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i',
            ],
            ['[token]', 'Bearer [redacted]', '[uuid]'],
            $text
        );

        $clean = trim(preg_replace('/\s+/', ' ', $clean) ?? $clean);

        if ($clean === '') {
            return null;
        }

        return strlen($clean) > $max ? substr($clean, 0, $max) . '...' : $clean;
    }

    /**
     * A one line summary of an exception, without the message of a nested cause.
     */
    public static function exception(Throwable $exception): string
    {
        return sprintf('%s: %s', $exception::class, self::text($exception->getMessage()) ?? '(no message)');
    }
}
