<?php

declare(strict_types=1);

namespace Expo\Push\Tests\Unit;

use Expo\Push\Exception\InvalidMessageException;
use Expo\Push\PushMessage;
use Expo\Push\Support\Json;
use Expo\Push\Tests\Support\FakeHttpClient;
use Expo\Push\Tests\Support\TestCase;
use Generator;
use PHPUnit\Framework\Attributes\DataProvider;
use stdClass;

/**
 * A recursive value raises. It never exhausts the memory of a worker.
 *
 * The reviewed snapshot walked arrays and objects before `json_encode()` could
 * report the loop, so `$object->self = $object` ended the process.
 */
final class RecursiveDataTest extends TestCase
{
    public function testASelfReferencingObjectRaises(): void
    {
        $object = new stdClass();
        $object->self = $object;

        $this->expectException(InvalidMessageException::class);
        $this->expectExceptionMessage('refers back to an object that holds it');

        self::ignoreResult(PushMessage::to(self::TOKEN_A)->data($object));
    }

    public function testAnObjectThatHoldsItselfDeeperDownRaises(): void
    {
        $outer = new stdClass();
        $inner = new stdClass();
        $outer->branch = $inner;
        $inner->back = $outer;

        $this->expectException(InvalidMessageException::class);
        $this->expectExceptionMessage('refers back to an object that holds it');

        self::ignoreResult(PushMessage::to(self::TOKEN_A)->data($outer));
    }

    /**
     * An object cycle through a plain array is still a cycle.
     */
    public function testAMixedArrayAndObjectCycleRaises(): void
    {
        $outer = new stdClass();
        $inner = new stdClass();
        $outer->branch = ['list' => [$inner]];
        $inner->back = $outer;

        $this->expectException(InvalidMessageException::class);
        $this->expectExceptionMessage('refers back to an object that holds it');

        self::ignoreResult(PushMessage::to(self::TOKEN_A)->data($outer));
    }

    public function testASelfReferencingArrayRaises(): void
    {
        $array = [];
        $array['self'] = &$array;

        /** @var array<string, mixed> $array */
        $this->expectException(InvalidMessageException::class);
        $this->expectExceptionMessage('nests deeper than');

        self::ignoreResult(PushMessage::to(self::TOKEN_A)->data($array));
    }

    public function testExcessiveAcyclicNestingRaises(): void
    {
        $deep = ['leaf' => 1];

        for ($i = 0; $i < Json::MAX_DEPTH + 10; ++$i) {
            $deep = ['n' => $deep];
        }

        $this->expectException(InvalidMessageException::class);
        $this->expectExceptionMessage('nests deeper than 512 levels');

        self::ignoreResult(PushMessage::to(self::TOKEN_A)->data($deep));
    }

    /**
     * The message that the error names stays short enough to read.
     */
    public function testTheErrorOfADeepValueNamesAShortPath(): void
    {
        $deep = ['leaf' => 1];

        for ($i = 0; $i < Json::MAX_DEPTH + 10; ++$i) {
            $deep = ['averylongkeyname' => $deep];
        }

        try {
            self::ignoreResult(PushMessage::to(self::TOKEN_A)->data($deep));
            self::fail('The SDK accepted a value of 522 levels.');
        } catch (InvalidMessageException $exception) {
            self::assertLessThan(300, strlen($exception->getMessage()));
            self::assertStringContainsString('...', $exception->getMessage());
        }
    }

    public function testOrdinaryNestingStillPasses(): void
    {
        $data = [
            'order' => [
                'id' => 42,
                'lines' => [
                    ['sku' => 'a', 'qty' => 1],
                    ['sku' => 'b', 'qty' => 2],
                ],
                'meta' => (object) ['source' => 'web'],
            ],
        ];

        $message = PushMessage::to(self::TOKEN_A)->data($data);

        self::assertSame(
            '{"to":"' . self::TOKEN_A . '","data":{"order":{"id":42,"lines":[{"sku":"a","qty":1},'
            . '{"sku":"b","qty":2}],"meta":{"source":"web"}}}}',
            Json::encode($message->jsonSerialize())
        );
    }

    /**
     * Nesting right at the limit is valid, and it still encodes.
     */
    public function testNestingAtTheLimitStillPasses(): void
    {
        $deep = ['leaf' => 1];

        // The value itself is level 1, so 510 wrappers keep the leaf at 511.
        for ($i = 0; $i < 510; ++$i) {
            $deep = ['n' => $deep];
        }

        $message = PushMessage::to(self::TOKEN_A)->data($deep);

        self::assertGreaterThan(1_000, strlen(Json::encode($message->data)));
    }

    /**
     * The same object under two branches is not a cycle.
     */
    public function testARepeatedObjectReferenceIsNotACycle(): void
    {
        $shared = (object) ['n' => 1];

        $message = PushMessage::to(self::TOKEN_A)->data(['a' => $shared, 'b' => $shared, 'c' => ['d' => $shared]]);

        self::assertSame(
            '{"a":{"n":1},"b":{"n":1},"c":{"d":{"n":1}}}',
            Json::encode($message->data)
        );
    }

    /**
     * Valid JSON semantics survive the bounded copy.
     */
    public function testTheBoundedCopyKeepsEveryValidJsonShape(): void
    {
        $message = PushMessage::to(self::TOKEN_A)->data([
            'emptyObject' => new stdClass(),
            'emptyList' => [],
            'list' => [1, 2, 3],
            'unicode' => 'blåbærsyltetøy 🫐',
            'numericString' => '007',
            'zero' => 0,
            'false' => false,
            'null' => null,
            'float' => 1.5,
        ]);

        self::assertSame(
            '{"emptyObject":{},"emptyList":[],"list":[1,2,3],"unicode":"blåbærsyltetøy 🫐",'
            . '"numericString":"007","zero":0,"false":false,"null":null,"float":1.5}',
            Json::encode($message->data)
        );
    }

    /**
     * A generator that produces a broken message later still stops every
     * request. The whole operation is checked before the first chunk goes out.
     */
    public function testAGeneratorWithALaterCycleSendsNothing(): void
    {
        $http = (new FakeHttpClient())->queue(['data' => self::okTickets(1)]);

        $messages = static function (): Generator {
            yield PushMessage::to(self::TOKEN_A)->title('first');

            $object = new stdClass();
            $object->self = $object;

            yield PushMessage::to(self::TOKEN_B)->title('second')->data($object);
        };

        try {
            self::ignoreResult($this->expo($http)->send($messages()));
            self::fail('The SDK sent a batch that holds a loop.');
        } catch (InvalidMessageException $exception) {
            self::assertStringContainsString('refers back to an object', $exception->getMessage());
        }

        self::assertSame(0, $http->requestCount(), 'no request may go out before the input is checked');
    }

    /**
     * The same check for a value that is only too deep.
     */
    public function testAGeneratorWithALaterDeepValueSendsNothing(): void
    {
        $http = (new FakeHttpClient())->queue(['data' => self::okTickets(1)]);

        $messages = static function (): Generator {
            yield PushMessage::to(self::TOKEN_A)->title('first');

            $deep = ['leaf' => 1];

            for ($i = 0; $i < Json::MAX_DEPTH + 10; ++$i) {
                $deep = ['n' => $deep];
            }

            yield PushMessage::to(self::TOKEN_B)->title('second')->data($deep);
        };

        $this->expectException(InvalidMessageException::class);

        try {
            self::ignoreResult($this->expo($http)->send($messages()));
        } finally {
            self::assertSame(0, $http->requestCount());
        }
    }

    /**
     * The guard holds in a process of its own, with a small memory limit.
     *
     * Without it the child would die with "Allowed memory size exhausted". The
     * test keeps that reproduction away from the test runner.
     */
    #[DataProvider('crashCases')]
    public function testARecursiveValueFailsInABoundedWayInItsOwnProcess(string $case): void
    {
        $script = dirname(__DIR__) . '/Support/recursive-data.php';
        $command = sprintf(
            '%s -d memory_limit=64M %s %s',
            escapeshellarg(PHP_BINARY),
            escapeshellarg($script),
            escapeshellarg($case)
        );

        $output = [];
        $status = 0;
        exec($command . ' 2>&1', $output, $status);

        $text = implode("\n", $output);

        self::assertSame(0, $status, 'the child process must end cleanly: ' . $text);
        self::assertStringContainsString('CAUGHT:', $text);
        self::assertStringNotContainsString('Allowed memory size', $text);
        self::assertStringNotContainsString('Fatal error', $text);
    }

    /**
     * @return Generator<string, array{string}>
     */
    public static function crashCases(): Generator
    {
        yield 'an object that holds itself' => ['self-object'];
        yield 'an array that holds itself' => ['self-array'];
        yield 'an object cycle through an array' => ['mixed-cycle'];
        yield 'twenty thousand levels of nesting' => ['deep-array'];
    }
}
