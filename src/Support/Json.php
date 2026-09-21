<?php

declare(strict_types=1);

namespace Expo\Push\Support;

use Expo\Push\Exception\InvalidMessageException;
use JsonException;
use stdClass;

/**
 * JSON encoding and decoding that always tells you when it fails.
 *
 * The SDK never turns a failed encode into an empty string or a zero byte count.
 * An invalid value raises `InvalidMessageException` before any request goes out.
 */
final class Json
{
    public const int FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

    /**
     * Encodes a value, or raises `InvalidMessageException`.
     *
     * Invalid UTF-8, a recursive value, a resource, NAN and INF all fail here.
     *
     * @param string $context the name that goes into the error message
     *
     * @throws InvalidMessageException
     */
    public static function encode(mixed $value, string $context = 'value'): string
    {
        try {
            return json_encode($value, self::FLAGS | JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidMessageException(
                sprintf('The SDK cannot encode the %s as JSON: %s', $context, $exception->getMessage()),
                0,
                $exception
            );
        }
    }

    /**
     * The size of the encoded value in bytes.
     *
     * @throws InvalidMessageException
     */
    public static function byteSize(mixed $value, string $context = 'value'): int
    {
        return strlen(self::encode($value, $context));
    }

    /**
     * Decodes a JSON document and keeps objects as `stdClass`.
     *
     * The receipt endpoint answers with a JSON object, and the send endpoint with
     * a JSON array. PHP arrays cannot tell `{}` from `[]`, so the SDK decodes
     * into objects and looks at the type itself.
     *
     * @return array{ok: true, value: mixed}|array{ok: false, error: string}
     */
    public static function tryDecode(string $json): array
    {
        if (trim($json) === '') {
            return ['ok' => false, 'error' => 'the body is empty'];
        }

        try {
            /** @var mixed $value */
            $value = json_decode($json, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            return ['ok' => false, 'error' => $exception->getMessage()];
        }

        return ['ok' => true, 'value' => $value];
    }

    /**
     * Copies the top level of a decoded `stdClass` into an associative array.
     *
     * A nested object stays a `stdClass`. Use `deepArray()` to convert every level.
     *
     * @return array<string, mixed>
     */
    public static function objectToArray(stdClass $object): array
    {
        $result = [];

        /** @var mixed $value */
        foreach (get_object_vars($object) as $key => $value) {
            $result[$key] = $value;
        }

        return $result;
    }

    /**
     * Turns every nested `stdClass` into an associative array.
     *
     * The SDK uses this for one entry of an answer, after it read the shape of
     * the envelope. Inside an entry the object and list difference no longer
     * changes any decision, and an array is simpler to read.
     */
    public static function deepArray(mixed $value): mixed
    {
        if ($value instanceof stdClass) {
            $value = get_object_vars($value);
        }

        if (!is_array($value)) {
            return $value;
        }

        $result = [];

        /** @var mixed $item */
        foreach ($value as $key => $item) {
            /** @var mixed $converted */
            $converted = self::deepArray($item);
            $result[$key] = $converted;
        }

        return $result;
    }

    /**
     * The same as `deepArray()`, for an entry that must be an object.
     *
     * @return array<string, mixed>
     */
    public static function entryToArray(stdClass $entry): array
    {
        /** @var array<string, mixed> $array */
        $array = self::deepArray($entry);

        return $array;
    }

    /**
     * True when the value is a JSON list: a PHP array with the keys 0, 1, 2 and so on.
     *
     * @phpstan-assert-if-true list<mixed> $value
     */
    public static function isList(mixed $value): bool
    {
        return is_array($value) && array_is_list($value);
    }

    /**
     * True when the string is valid UTF-8. It needs PCRE only, not ext-mbstring.
     */
    public static function isUtf8(string $value): bool
    {
        return $value === '' || preg_match('//u', $value) === 1;
    }

    /**
     * Copies a value that came from the application and rejects what JSON cannot hold.
     *
     * An array is a value in PHP, so it needs no copy. A `stdClass` is a reference,
     * so this method clones it. Any other object, and any resource, raises an
     * exception: the SDK does not guess how to serialize your objects.
     *
     * @throws InvalidMessageException
     */
    public static function snapshot(mixed $value, string $path = 'data'): mixed
    {
        if ($value === null || is_scalar($value)) {
            if (is_float($value) && !is_finite($value)) {
                throw new InvalidMessageException(sprintf(
                    'The %s holds %s. JSON has no value for it.',
                    $path,
                    is_nan($value) ? 'NAN' : 'INF'
                ));
            }

            if (is_string($value) && !self::isUtf8($value)) {
                throw new InvalidMessageException(sprintf('The %s holds a string that is not valid UTF-8.', $path));
            }

            return $value;
        }

        if (is_array($value)) {
            $copy = [];

            /** @var mixed $item */
            foreach ($value as $key => $item) {
                if (is_string($key) && !self::isUtf8($key)) {
                    throw new InvalidMessageException(sprintf('The %s has a key that is not valid UTF-8.', $path));
                }

                /** @var mixed $copied */
                $copied = self::snapshot($item, $path . '.' . $key);
                $copy[$key] = $copied;
            }

            return $copy;
        }

        if ($value instanceof stdClass) {
            $copy = new stdClass();

            /** @var mixed $item */
            foreach (get_object_vars($value) as $key => $item) {
                /** @var mixed $copied */
                $copied = self::snapshot($item, $path . '.' . $key);
                $copy->{$key} = $copied;
            }

            return $copy;
        }

        throw new InvalidMessageException(sprintf(
            'The %s holds a %s. Give an array, a stdClass or a scalar.',
            $path,
            get_debug_type($value)
        ));
    }
}
