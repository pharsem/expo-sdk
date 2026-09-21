<?php

declare(strict_types=1);

namespace Expo\Push\Retry;

use Expo\Push\Http\HttpResponse;

/**
 * Reads the `Retry-After` header of RFC 9110.
 *
 * The header holds either a number of seconds or an HTTP date. The SDK accepts
 * both, in any header name case, with whitespace around the value.
 *
 * The SDK treats a valid value as a lower bound. It never shortens it to a local
 * cap: a server that asks for 120 seconds gets 120 seconds.
 */
final class RetryAfter
{
    /**
     * The largest delay that the SDK reads from a header, in milliseconds.
     *
     * The cap stops an overflow from a very large number or a date far in the
     * future. It is one day.
     */
    public const int MAX_MILLIS = 86_400_000;

    /**
     * The delay in milliseconds, or null when the header is missing or invalid.
     *
     * A zero value and a date in the past both give 0, which means "retry now".
     *
     * @param int $nowUtcMillis the current UTC wall time, for a date value
     */
    public static function fromResponse(HttpResponse $response, int $nowUtcMillis): ?int
    {
        return self::parse($response->header('retry-after'), $nowUtcMillis);
    }

    /**
     * @param int $nowUtcMillis the current UTC wall time, for a date value
     */
    public static function parse(?string $value, int $nowUtcMillis): ?int
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        if ($trimmed === '') {
            return null;
        }

        if (preg_match('/^\d+$/', $trimmed) === 1) {
            $seconds = (float) $trimmed;

            return (int) min($seconds * 1000, self::MAX_MILLIS);
        }

        $date = self::parseHttpDate($trimmed);

        if ($date === null) {
            return null;
        }

        return (int) max(0, min($date - $nowUtcMillis, self::MAX_MILLIS));
    }

    /**
     * Reads an HTTP date and returns UTC milliseconds, or null.
     *
     * RFC 9110 names three formats and the SDK reads exactly those three. A
     * loose parser would accept "next monday" and other text that no server
     * sends, so the SDK does not use one.
     */
    private static function parseHttpDate(string $value): ?int
    {
        $formats = [
            'D, d M Y H:i:s \G\M\T',   // IMF-fixdate: Sun, 06 Nov 1994 08:49:37 GMT
            'l, d-M-y H:i:s \G\M\T',   // RFC 850:     Sunday, 06-Nov-94 08:49:37 GMT
            'D M j H:i:s Y',           // asctime:     Sun Nov  6 08:49:37 1994
        ];

        $utc = new \DateTimeZone('UTC');

        foreach ($formats as $format) {
            $date = \DateTimeImmutable::createFromFormat('!' . $format, $value, $utc);

            if ($date instanceof \DateTimeImmutable) {
                return $date->getTimestamp() * 1000;
            }
        }

        return null;
    }
}
