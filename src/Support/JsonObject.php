<?php

declare(strict_types=1);

namespace Expo\Push\Support;

use ArrayIterator;
use Countable;
use Expo\Push\Exception\InvalidMessageException;
use IteratorAggregate;
use JsonSerializable;
use LogicException;
use OutOfBoundsException;
use stdClass;
use Traversable;

/**
 * A JSON object that nothing can change after the SDK built it.
 *
 * `PushMessage` holds the custom data of a message in this shape. A `stdClass`
 * is a reference, so a message that kept one would still change when the
 * application changed the object it passed in. This class holds a copy that has
 * no writable state at all, at every level.
 *
 * Reading works the way a `stdClass` reads:
 *
 * ```php
 * $message = PushMessage::to($token)->data((object) ['orderId' => 123]);
 *
 * echo $message->data->orderId;        // 123
 * echo $message->data->missing ?? '-'; // -
 * $message->data->orderId = 456;       // LogicException
 * ```
 *
 * Two habits of `stdClass` do not carry over: `get_object_vars()` returns an
 * empty array, and an `(array)` cast gives the internal field. Use `toArray()`
 * for both.
 *
 * @implements IteratorAggregate<string, mixed>
 */
final readonly class JsonObject implements JsonSerializable, IteratorAggregate, Countable
{
    /**
     * @param array<string, mixed> $values already checked and copied by `Json::snapshot()`
     * @param int                  $depth  how many levels this object holds, itself included
     */
    private function __construct(
        private array $values,
        private int $depth = 1,
    ) {
    }

    /**
     * How many levels this object holds, itself included.
     *
     * The check in `fromNormalized()` counts them once. A caller that nests
     * this object under another value adds its own levels to this number, and
     * the sum has to fit `Json::MAX_DEPTH`.
     */
    public function depth(): int
    {
        return $this->depth;
    }

    /**
     * Wraps values that hold nothing mutable and nothing that JSON refuses.
     *
     * `Json::snapshot()` builds this array from the bottom up, so the check
     * here only reads. It needs no copy: a PHP array is a value, and a nested
     * `JsonObject` cannot change either.
     *
     * The check runs for every caller, not only for the normalizer. A wrapper
     * around a live `stdClass` would give the mutation path back.
     *
     * @param array<string, mixed> $values
     *
     * @throws \Expo\Push\Exception\InvalidMessageException when a value is
     *                                                      mutable, or JSON
     *                                                      cannot hold it
     *
     * @internal
     */
    public static function fromNormalized(array $values): self
    {
        return new self($values, self::checkedDepth($values, 1, 'data'));
    }

    /**
     * The number of levels below this one, and the check of every value.
     *
     * @param array<array-key, mixed> $values
     *
     * @throws \Expo\Push\Exception\InvalidMessageException
     */
    private static function checkedDepth(array $values, int $depth, string $path): int
    {
        if ($depth > Json::MAX_DEPTH) {
            throw new InvalidMessageException(sprintf(
                'The %s nests deeper than %d levels. JSON encoding stops there.',
                $path,
                Json::MAX_DEPTH
            ));
        }

        $deepest = $depth;

        /** @var mixed $value */
        foreach ($values as $key => $value) {
            $here = $path . '.' . $key;

            if (is_string($key) && !Json::isUtf8($key)) {
                throw new InvalidMessageException(sprintf('The %s has a key that is not valid UTF-8.', $path));
            }

            if ($value instanceof self) {
                // The nested object counted its own levels when it was built.
                $deepest = max($deepest, $depth + $value->depth());

                if ($deepest > Json::MAX_DEPTH) {
                    throw new InvalidMessageException(sprintf(
                        'The %s nests deeper than %d levels. JSON encoding stops there.',
                        $here,
                        Json::MAX_DEPTH
                    ));
                }

                continue;
            }

            if (is_array($value)) {
                $deepest = max($deepest, self::checkedDepth($value, $depth + 1, $here));

                continue;
            }

            if ($value === null || is_bool($value) || is_int($value)) {
                continue;
            }

            if (is_float($value)) {
                if (!is_finite($value)) {
                    throw new InvalidMessageException(sprintf(
                        'The %s holds %s. JSON has no value for it.',
                        $here,
                        is_nan($value) ? 'NAN' : 'INF'
                    ));
                }

                continue;
            }

            if (is_string($value)) {
                if (!Json::isUtf8($value)) {
                    throw new InvalidMessageException(sprintf(
                        'The %s holds a string that is not valid UTF-8.',
                        $here
                    ));
                }

                continue;
            }

            throw new InvalidMessageException(sprintf(
                'The %s holds a %s. A JsonObject holds only immutable JSON values.',
                $here,
                get_debug_type($value)
            ));
        }

        return $deepest;
    }

    /**
     * Copies and checks any JSON object, and rejects what JSON cannot hold.
     *
     * @param array<string, mixed>|stdClass $value
     *
     * @throws \Expo\Push\Exception\InvalidMessageException
     */
    public static function from(array|stdClass $value): self
    {
        if (is_array($value)) {
            $value = (object) $value;
        }

        /** @var self $object */
        $object = Json::snapshot($value);

        return $object;
    }

    /**
     * The value behind one key.
     *
     * @throws OutOfBoundsException when the object holds no such key. Use
     *                              `isset()` or the `??` operator to ask first
     */
    public function __get(string $name): mixed
    {
        if (!array_key_exists($name, $this->values)) {
            throw new OutOfBoundsException(sprintf('The message data holds no "%s" key.', $name));
        }

        return $this->values[$name];
    }

    public function __isset(string $name): bool
    {
        return isset($this->values[$name]);
    }

    /**
     * @throws LogicException always. A message never changes after you build it
     */
    public function __set(string $name, mixed $value): never
    {
        throw new LogicException(sprintf(
            'The message data is immutable, so "%s" cannot change. Build a new message with data() or withDatum().',
            $name
        ));
    }

    /**
     * @throws LogicException always. A message never changes after you build it
     */
    public function __unset(string $name): never
    {
        throw new LogicException(sprintf(
            'The message data is immutable, so "%s" cannot go away. Build a new message with data().',
            $name
        ));
    }

    public function has(string $name): bool
    {
        return array_key_exists($name, $this->values);
    }

    /**
     * The value behind one key, or the fallback when there is none.
     */
    public function get(string $name, mixed $default = null): mixed
    {
        return array_key_exists($name, $this->values) ? $this->values[$name] : $default;
    }

    /**
     * Every key and value of this object.
     *
     * A nested JSON object stays a `JsonObject`, so the result cannot change the
     * message either.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->values;
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys($this->values);
    }

    #[\Override]
    public function count(): int
    {
        return count($this->values);
    }

    public function isEmpty(): bool
    {
        return $this->values === [];
    }

    /**
     * @return Traversable<string, mixed>
     */
    #[\Override]
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->values);
    }

    /**
     * The value in the shape that `json_encode()` writes as a JSON object.
     *
     * The common case needs no copy: a PHP array with at least one string key
     * already encodes as an object. An empty object, and an object whose keys
     * are all numeric, would encode as a list, so those get a `stdClass`.
     *
     * @return stdClass|array<string, mixed>
     */
    #[\Override]
    public function jsonSerialize(): stdClass|array
    {
        if (array_is_list($this->values)) {
            return (object) $this->values;
        }

        return $this->values;
    }
}
