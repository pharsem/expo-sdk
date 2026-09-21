<?php

declare(strict_types=1);

namespace Expo\Push\Tests\Unit;

use Expo\Push\PushMessage;
use Expo\Push\Support\Json;
use Expo\Push\Support\JsonObject;
use Expo\Push\Tests\Support\TestCase;
use LogicException;
use OutOfBoundsException;
use stdClass;

/**
 * Nothing outside a message can change what it sends.
 *
 * The reviewed message copied its data at construction, so the input object was
 * safe. The public readonly property still handed the copy out, so
 * `$message->data->orderId = 456` changed the payload.
 */
final class MessageImmutabilityTest extends TestCase
{
    public function testAWriteThroughTheReadPropertyIsRejected(): void
    {
        $message = PushMessage::to(self::TOKEN_A)->data((object) ['orderId' => 123]);
        $data = $message->data;

        self::assertInstanceOf(JsonObject::class, $data);
        self::assertSame(123, $data->orderId);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('immutable');

        $data->orderId = 456;
    }

    public function testTheOutputNeverChangesWhenAWriteIsRejected(): void
    {
        $message = PushMessage::to(self::TOKEN_A)->data((object) ['orderId' => 123]);
        $data = $message->data;

        self::assertInstanceOf(JsonObject::class, $data);

        $before = Json::encode($message->jsonSerialize());

        try {
            // PHP routes every write on this object here, so the explicit call
            // and `$data->orderId = 456` reach the same guard.
            $data->__set('orderId', 456);
        } catch (LogicException $exception) {
            self::assertStringContainsString('immutable', $exception->getMessage());
        }

        self::assertSame($before, Json::encode($message->jsonSerialize()));
        self::assertSame(123, $data->orderId);
    }

    public function testUnsetThroughTheReadPropertyIsRejected(): void
    {
        $message = PushMessage::to(self::TOKEN_A)->data((object) ['orderId' => 123]);
        $data = $message->data;

        self::assertInstanceOf(JsonObject::class, $data);

        $this->expectException(LogicException::class);

        unset($data->orderId);
    }

    public function testAChangeToTheInputObjectNeverReachesTheMessage(): void
    {
        $input = (object) ['orderId' => 123, 'nested' => (object) ['deep' => 1]];
        $message = PushMessage::to(self::TOKEN_A)->data($input);
        $before = Json::encode($message->jsonSerialize());

        $input->orderId = 456;
        $input->nested->deep = 2;
        $input->fresh = 'new';

        self::assertSame($before, Json::encode($message->jsonSerialize()));
        self::assertSame('{"orderId":123,"nested":{"deep":1}}', Json::encode($message->data));
    }

    public function testANestedObjectInsideAnArrayCannotChangeTheMessage(): void
    {
        $message = PushMessage::to(self::TOKEN_A)->data(['payload' => (object) ['n' => 1]]);
        $before = Json::encode($message->jsonSerialize());

        $data = $message->data;

        self::assertIsArray($data);

        $payload = $data['payload'];

        self::assertInstanceOf(JsonObject::class, $payload);

        try {
            $payload->__set('n', 99);
        } catch (LogicException $exception) {
            self::assertStringContainsString('immutable', $exception->getMessage());
        }

        self::assertSame($before, Json::encode($message->jsonSerialize()));
    }

    /**
     * Every representation that the message hands out.
     *
     * @return iterable<string, array{callable(PushMessage): mixed}>
     */
    public static function representations(): iterable
    {
        yield 'the data property' => [static fn (PushMessage $m): mixed => $m->data];
        yield 'toExpoArray' => [static fn (PushMessage $m): mixed => $m->toExpoArray()['data'] ?? null];
        yield 'jsonSerialize' => [static fn (PushMessage $m): mixed => $m->jsonSerialize()['data'] ?? null];
        yield 'toStorageArray' => [static function (PushMessage $m): mixed {
            $stored = $m->toStorageArray();
            /** @var array<string, mixed> $data */
            $data = $stored['data'];

            return $data['data'] ?? null;
        }];
    }

    /**
     * @param callable(PushMessage): mixed $read
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('representations')]
    public function testNoRepresentationHandsOutAMutableObject(callable $read): void
    {
        $message = PushMessage::to(self::TOKEN_A)
            ->data(['top' => (object) ['n' => 1], 'list' => [(object) ['m' => 2]]]);

        $before = Json::encode($message->jsonSerialize());

        /** @var mixed $value */
        $value = $read($message);

        self::assertNotInstanceOf(stdClass::class, $value);

        foreach (self::objectsIn($value) as $object) {
            self::assertInstanceOf(JsonObject::class, $object);
        }

        self::assertSame($before, Json::encode($message->jsonSerialize()));
    }

    /**
     * @return list<object>
     */
    private static function objectsIn(mixed $value): array
    {
        if (is_object($value)) {
            $found = [$value];

            if ($value instanceof JsonObject) {
                /** @var mixed $item */
                foreach ($value->toArray() as $item) {
                    $found = [...$found, ...self::objectsIn($item)];
                }
            }

            return $found;
        }

        if (!is_array($value)) {
            return [];
        }

        $found = [];

        /** @var mixed $item */
        foreach ($value as $item) {
            $found = [...$found, ...self::objectsIn($item)];
        }

        return $found;
    }

    public function testAFluentCopyNeverChangesTheMessageItCameFrom(): void
    {
        $first = PushMessage::to(self::TOKEN_A)->data((object) ['a' => 1]);
        $second = $first->withDatum('b', 2);
        $third = $second->title('Hi');

        self::assertSame('{"a":1}', Json::encode($first->data));
        self::assertSame('{"a":1,"b":2}', Json::encode($second->data));
        self::assertSame('{"a":1,"b":2}', Json::encode($third->data));
        self::assertNull($second->title);
        self::assertSame('Hi', $third->title);
    }

    public function testAFluentCopyOfAnArrayStaysAnArray(): void
    {
        $first = PushMessage::to(self::TOKEN_A)->data(['a' => 1]);
        $second = $first->withDatum('b', 2);

        self::assertSame(['a' => 1], $first->data);
        self::assertSame(['a' => 1, 'b' => 2], $second->data);
    }

    /**
     * An object with numeric keys stays an object, before and after a copy.
     */
    public function testAnObjectWithNumericKeysNeverBecomesAList(): void
    {
        $message = PushMessage::to(self::TOKEN_A)->data((object) ['0' => 'a', '1' => 'b']);

        self::assertSame('{"0":"a","1":"b"}', Json::encode($message->data));
        self::assertSame('{"0":"a","1":"b","2":"c"}', Json::encode($message->withDatum('2', 'c')->data));
    }

    public function testAnEmptyObjectStaysAnEmptyObjectOnTheWire(): void
    {
        $message = PushMessage::to(self::TOKEN_A)->data(new stdClass());

        self::assertSame(
            '{"to":"' . self::TOKEN_A . '","data":{}}',
            Json::encode($message->jsonSerialize())
        );
        self::assertTrue($message->data instanceof JsonObject && $message->data->isEmpty());
    }

    public function testTheReadApiOfTheDataObject(): void
    {
        $message = PushMessage::to(self::TOKEN_A)->data((object) ['a' => 1, 'b' => null]);
        $data = $message->data;

        self::assertInstanceOf(JsonObject::class, $data);
        self::assertSame(1, $data->a);
        self::assertSame(1, $data->get('a'));
        self::assertNull($data->get('missing'));
        self::assertSame('fallback', $data->get('missing', 'fallback'));
        self::assertTrue($data->has('b'));
        self::assertFalse(isset($data->b), 'a null value is not set, exactly as on a stdClass');
        self::assertFalse($data->has('missing'));
        self::assertSame(['a', 'b'], $data->keys());
        self::assertCount(2, $data);
        self::assertSame(['a' => 1, 'b' => null], iterator_to_array($data));
        self::assertSame('fallback', $data->missing ?? 'fallback');
    }

    public function testReadingAKeyThatIsNotThereRaises(): void
    {
        $message = PushMessage::to(self::TOKEN_A)->data((object) ['a' => 1]);

        $data = $message->data;

        self::assertInstanceOf(JsonObject::class, $data);

        $this->expectException(OutOfBoundsException::class);
        $this->expectExceptionMessage('holds no "missing" key');

        self::ignoreResult($data->missing);
    }

    /**
     * The message reads its own data back, whatever shape it came in.
     */
    public function testAMessageAcceptsItsOwnDataObjectAgain(): void
    {
        $first = PushMessage::to(self::TOKEN_A)->data((object) ['a' => 1]);
        $second = PushMessage::to(self::TOKEN_B)->data($first->data);

        self::assertSame('{"a":1}', Json::encode($second->data));
        self::assertSame($first->data, $second->data, 'a checked object needs no second copy');
    }

    public function testTheStorageRoundTripKeepsTheValues(): void
    {
        $message = PushMessage::to(self::TOKEN_A)
            ->title('Hi')
            ->data((object) ['orderId' => 42, 'flags' => ['a' => true, 'b' => false]]);

        $json = json_encode($message->toStorageArray(), JSON_THROW_ON_ERROR);
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        self::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        $restored = PushMessage::fromStorageArray($decoded);

        self::assertSame(
            ['orderId' => 42, 'flags' => ['a' => true, 'b' => false]],
            $restored->data
        );
    }
}
