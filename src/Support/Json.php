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
     * The deepest value that `snapshot()` copies.
     *
     * The number is the default nesting limit of `json_encode()` and
     * `json_decode()`, so anything that passes the copy also encodes. A deeper
     * value raises `InvalidMessageException` before the traversal can exhaust
     * the memory limit or the process stack.
     */
    public const int MAX_DEPTH = 512;

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
     * True when two decoded JSON values mean the same thing.
     *
     * The comparison keeps the difference between a JSON object and a JSON
     * list, and it keeps the order inside a list. It ignores the order of the
     * keys inside an object, because JSON gives that order no meaning. A
     * `stdClass` and a `JsonObject` with the same keys are equal, and an object
     * never equals a list.
     */
    public static function sameJson(mixed $first, mixed $second): bool
    {
        $firstMap = self::asObjectMap($first);
        $secondMap = self::asObjectMap($second);

        if ($firstMap !== null || $secondMap !== null) {
            if ($firstMap === null || $secondMap === null) {
                return false;
            }

            return self::sameMap($firstMap, $secondMap);
        }

        if (is_array($first) || is_array($second)) {
            if (!is_array($first) || !is_array($second)) {
                return false;
            }

            // Both are PHP arrays here, so both are lists or both are maps.
            if (array_is_list($first) !== array_is_list($second)) {
                return false;
            }

            return self::sameMap($first, $second);
        }

        if (is_float($first) || is_float($second)) {
            // A float and an int of the same value encode differently, so they
            // are not the same JSON. NAN never reaches a stored receipt.
            return is_float($first) && is_float($second) && $first === $second;
        }

        return $first === $second;
    }

    /**
     * The key and value pairs of a JSON object, or null when the value is not one.
     *
     * A PHP array is ambiguous on its own, so only a real object counts here.
     *
     * @return array<array-key, mixed>|null
     */
    private static function asObjectMap(mixed $value): ?array
    {
        if ($value instanceof stdClass) {
            return get_object_vars($value);
        }

        if ($value instanceof JsonObject) {
            return $value->toArray();
        }

        return null;
    }

    /**
     * @param array<array-key, mixed> $first
     * @param array<array-key, mixed> $second
     */
    private static function sameMap(array $first, array $second): bool
    {
        if (count($first) !== count($second)) {
            return false;
        }

        /** @var mixed $value */
        foreach ($first as $key => $value) {
            if (!array_key_exists($key, $second)) {
                return false;
            }

            if (!self::sameJson($value, $second[$key])) {
                return false;
            }
        }

        return true;
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
     * so this method clones it as an immutable `JsonObject`. Any other object, and
     * any resource, raises an exception: the SDK does not guess how to serialize
     * your objects.
     *
     * The traversal is bounded twice. It refuses a value deeper than
     * `MAX_DEPTH`, and it refuses an object that appears again on its own path.
     * A recursive value therefore raises a catchable exception instead of
     * exhausting the memory limit. The same object under two separate branches
     * stays valid: only a cycle is a cycle.
     *
     * @throws InvalidMessageException
     */
    public static function snapshot(mixed $value, string $path = 'data'): mixed
    {
        return self::copy($value, $path, 1, []);
    }

    /**
     * A long path, with the middle replaced by an ellipsis.
     *
     * A value that is 512 levels deep would otherwise print 512 key names, and
     * the first and the last few say everything that a reader needs.
     */
    private static function shortPath(string $path): string
    {
        if (strlen($path) <= 120) {
            return $path;
        }

        return substr($path, 0, 60) . ' ... ' . substr($path, -40);
    }

    /**
     * @param array<int, true> $ancestors the object IDs on the path to this value
     *
     * @throws InvalidMessageException
     */
    private static function copy(mixed $value, string $path, int $depth, array $ancestors): mixed
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

        if ($depth > self::MAX_DEPTH) {
            throw new InvalidMessageException(sprintf(
                'The %s nests deeper than %d levels. JSON encoding stops there, and a value that deep is often a '
                . 'loop. Flatten the data.',
                self::shortPath($path),
                self::MAX_DEPTH
            ));
        }

        if (is_array($value)) {
            $copy = [];

            /** @var mixed $item */
            foreach ($value as $key => $item) {
                if (is_string($key) && !self::isUtf8($key)) {
                    throw new InvalidMessageException(sprintf('The %s has a key that is not valid UTF-8.', $path));
                }

                /** @var mixed $copied */
                $copied = self::copy($item, $path . '.' . $key, $depth + 1, $ancestors);
                $copy[$key] = $copied;
            }

            return $copy;
        }

        if ($value instanceof JsonObject) {
            // The value is already a bounded, immutable copy of valid data.
            // Copying it again would only cost time.
            return $value;
        }

        if ($value instanceof stdClass) {
            $id = spl_object_id($value);

            if (isset($ancestors[$id])) {
                throw new InvalidMessageException(sprintf(
                    'The %s refers back to an object that holds it. JSON cannot hold a loop.',
                    self::shortPath($path)
                ));
            }

            $ancestors[$id] = true;
            $copy = [];

            /** @var mixed $item */
            foreach (get_object_vars($value) as $key => $item) {
                /** @var mixed $copied */
                $copied = self::copy($item, $path . '.' . $key, $depth + 1, $ancestors);
                $copy[$key] = $copied;
            }

            return JsonObject::fromNormalized($copy);
        }

        throw new InvalidMessageException(sprintf(
            'The %s holds a %s. Give an array, a stdClass or a scalar.',
            $path,
            get_debug_type($value)
        ));
    }
}
