<?php

declare(strict_types=1);

namespace Expo\Push\Support;

use ArrayIterator;
use Countable;
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
     */
    private function __construct(private array $values)
    {
    }

    /**
     * Wraps values that `Json::snapshot()` already checked and copied.
     *
     * @param array<string, mixed> $values
     *
     * @internal
     */
    public static function fromNormalized(array $values): self
    {
        return new self($values);
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
