<?php

declare(strict_types=1);

namespace Expo\Push\Tests\Unit;

use DateTimeImmutable;
use Expo\Push\Exception\InvalidMessageException;
use Expo\Push\Exception\InvalidTokenException;
use Expo\Push\Exception\MessageTooLargeException;
use Expo\Push\InterruptionLevel;
use Expo\Push\Priority;
use Expo\Push\PushMessage;
use Expo\Push\PushToken;
use Expo\Push\Sound;
use Expo\Push\Tests\Support\TestCase;
use stdClass;

final class PushMessageTest extends TestCase
{
    public function testTheFluentAndTheNamedFormGiveTheSameMessage(): void
    {
        $fluent = PushMessage::to(self::TOKEN_A)
            ->title('Title')
            ->body('Body')
            ->data(['orderId' => 42])
            ->badge(1)
            ->channelId('orders')
            ->highPriority();

        $named = new PushMessage(
            to: self::TOKEN_A,
            title: 'Title',
            body: 'Body',
            data: ['orderId' => 42],
            badge: 1,
            channelId: 'orders',
            priority: 'high',
        );

        self::assertSame($fluent->toExpoArray(), $named->toExpoArray());
        self::assertSame(Priority::High, $named->priority);
    }

    public function testEveryDocumentedFieldReachesTheWire(): void
    {
        $message = new PushMessage(
            to: self::TOKEN_A,
            title: 'Title',
            body: 'Body',
            data: ['a' => 1],
            subtitle: 'Subtitle',
            sound: 'bells.wav',
            ttl: 60,
            expiration: 1_700_000_000,
            priority: Priority::High,
            interruptionLevel: InterruptionLevel::TimeSensitive,
            badge: 3,
            channelId: 'orders',
            icon: 'ic_push',
            image: 'https://example.test/a.png',
            categoryId: 'actions',
            mutableContent: true,
            contentAvailable: true,
            collapseId: 'order-42',
            tag: 'order-42',
            threadId: 'orders',
            targetContentId: 'window-1',
            relevanceScore: 0.5,
            filterCriteria: 'focus',
            reference: 'correlation-1',
        );

        $payload = $message->toExpoArray();

        self::assertSame([
            'to' => self::TOKEN_A,
            'title' => 'Title',
            'subtitle' => 'Subtitle',
            'body' => 'Body',
            'data' => ['a' => 1],
            'sound' => 'bells.wav',
            'ttl' => 60,
            'expiration' => 1_700_000_000,
            'priority' => 'high',
            'interruptionLevel' => 'time-sensitive',
            'badge' => 3,
            'channelId' => 'orders',
            'icon' => 'ic_push',
            'richContent' => ['image' => 'https://example.test/a.png'],
            'categoryId' => 'actions',
            'mutableContent' => true,
            'contentAvailable' => true,
            'collapseId' => 'order-42',
            'tag' => 'order-42',
            'threadId' => 'orders',
            'targetContentId' => 'window-1',
            'relevanceScore' => 0.5,
            'filterCriteria' => 'focus',
        ], $payload);

        self::assertArrayNotHasKey('reference', $payload);
    }

    public function testFalseAndZeroStayOnTheWire(): void
    {
        $message = PushMessage::to(self::TOKEN_A)
            ->badge(0)
            ->ttl(0)
            ->mutableContent(false)
            ->contentAvailable(false)
            ->relevanceScore(0.0);

        $payload = $message->toExpoArray();

        self::assertSame(0, $payload['badge']);
        self::assertSame(0, $payload['ttl']);
        self::assertFalse($payload['mutableContent']);
        self::assertFalse($payload['contentAvailable']);
        self::assertSame(0.0, $payload['relevanceScore']);
    }

    public function testASilentSoundIsNotAMissingSound(): void
    {
        $silent = PushMessage::to(self::TOKEN_A)->silent()->toExpoArray();
        $missing = PushMessage::to(self::TOKEN_A)->toExpoArray();

        self::assertArrayHasKey('sound', $silent);
        self::assertNull($silent['sound']);
        self::assertArrayNotHasKey('sound', $missing);
    }

    public function testACriticalSoundKeepsItsVolume(): void
    {
        $payload = PushMessage::to(self::TOKEN_A)->sound(Sound::critical('alarm.wav', 0.8))->toExpoArray();

        self::assertSame(['critical' => true, 'name' => 'alarm.wav', 'volume' => 0.8], $payload['sound']);
    }

    public function testASoundVolumeOutOfRangeRaises(): void
    {
        $this->expectException(InvalidMessageException::class);

        self::ignoreResult(Sound::critical('a.wav', 1.5));
    }

    public function testANonFiniteSoundVolumeRaises(): void
    {
        $this->expectException(InvalidMessageException::class);

        self::ignoreResult(Sound::critical('a.wav', NAN));
    }

    public function testClearingAFieldRemovesItFromTheWire(): void
    {
        $message = PushMessage::to(self::TOKEN_A)->title('Title')->badge(2);
        $cleared = $message->title(null)->badge(null);

        self::assertArrayNotHasKey('title', $cleared->toExpoArray());
        self::assertArrayNotHasKey('badge', $cleared->toExpoArray());
        // The first message never changed.
        self::assertSame('Title', $message->title);
    }

    public function testEmptyDataGoesOutAsAJsonObject(): void
    {
        $message = PushMessage::to(self::TOKEN_A)->data([]);

        self::assertStringContainsString('"data":{}', json_encode($message->toExpoArray(), JSON_THROW_ON_ERROR));
    }

    public function testAStdClassIsAcceptedAsData(): void
    {
        $data = new stdClass();
        $data->orderId = 42;
        $data->nested = new stdClass();

        $message = PushMessage::to(self::TOKEN_A)->data($data);

        self::assertStringContainsString(
            '"data":{"orderId":42,"nested":{}}',
            json_encode($message->toExpoArray(), JSON_THROW_ON_ERROR)
        );
    }

    public function testATopLevelListIsRejected(): void
    {
        $this->expectException(InvalidMessageException::class);
        $this->expectExceptionMessage('must be a JSON object');

        /** @phpstan-ignore argument.type */
        self::ignoreResult(PushMessage::to(self::TOKEN_A)->data([1, 2, 3]));
    }

    public function testANestedListStaysAList(): void
    {
        $message = PushMessage::to(self::TOKEN_A)->data(['items' => [1, 2, 3], 'map' => ['a' => 1]]);

        self::assertStringContainsString(
            '"data":{"items":[1,2,3],"map":{"a":1}}',
            json_encode($message->toExpoArray(), JSON_THROW_ON_ERROR)
        );
    }

    public function testANumericStringStaysAString(): void
    {
        $message = PushMessage::to(self::TOKEN_A)->data(['orderId' => '00042']);

        self::assertStringContainsString('"orderId":"00042"', json_encode($message->toExpoArray(), JSON_THROW_ON_ERROR));
    }

    public function testAMutableObjectCannotChangeThroughTheMessage(): void
    {
        $data = new stdClass();
        $data->value = 'first';

        $message = PushMessage::to(self::TOKEN_A)->data($data);
        $data->value = 'second';

        self::assertStringContainsString('"value":"first"', json_encode($message->toExpoArray(), JSON_THROW_ON_ERROR));
    }

    public function testANestedObjectReferenceCannotChangeThroughTheMessage(): void
    {
        $nested = new stdClass();
        $nested->value = 'first';

        $message = PushMessage::to(self::TOKEN_A)->data(['nested' => $nested]);
        $nested->value = 'second';

        self::assertStringContainsString('"value":"first"', json_encode($message->toExpoArray(), JSON_THROW_ON_ERROR));
    }

    public function testAnObjectThatIsNotStdClassIsRejected(): void
    {
        $this->expectException(InvalidMessageException::class);
        $this->expectExceptionMessage('Give an array, a stdClass or a scalar');

        self::ignoreResult(PushMessage::to(self::TOKEN_A)->data(['when' => new DateTimeImmutable()]));
    }

    public function testNanAndInfinityAreRejected(): void
    {
        $this->expectException(InvalidMessageException::class);

        self::ignoreResult(PushMessage::to(self::TOKEN_A)->data(['value' => NAN]));
    }

    public function testInfinityIsRejected(): void
    {
        $this->expectException(InvalidMessageException::class);

        self::ignoreResult(PushMessage::to(self::TOKEN_A)->data(['value' => INF]));
    }

    public function testInvalidUtf8InDataIsRejected(): void
    {
        $this->expectException(InvalidMessageException::class);
        $this->expectExceptionMessage('not valid UTF-8');

        self::ignoreResult(PushMessage::to(self::TOKEN_A)->data(['value' => "\xB1\x31"]));
    }

    public function testInvalidUtf8InTheTitleIsRejected(): void
    {
        $this->expectException(InvalidMessageException::class);
        $this->expectExceptionMessage('title is not valid UTF-8');

        self::ignoreResult(PushMessage::to(self::TOKEN_A)->title("bad \xB1\x31"));
    }

    public function testAResourceInDataIsRejected(): void
    {
        $handle = fopen('php://memory', 'rb');
        self::assertIsResource($handle);

        try {
            $this->expectException(InvalidMessageException::class);
            self::ignoreResult(PushMessage::to(self::TOKEN_A)->data(['handle' => $handle]));
        } finally {
            fclose($handle);
        }
    }

    public function testTheSizeCountsUnicodeBytes(): void
    {
        $empty = PushMessage::to(self::TOKEN_A);
        $ascii = $empty->body(str_repeat('a', 10));
        $emoji = $empty->body(str_repeat('🎉', 10));

        // An empty message is {}, which is two bytes.
        self::assertSame(2, $empty->sizeInBytes());
        // {"body":"aaaaaaaaaa"} is 21 bytes.
        self::assertSame(21, $ascii->sizeInBytes());
        // Each emoji takes four UTF-8 bytes, so the body grows by 30 bytes.
        self::assertSame(51, $emoji->sizeInBytes());
    }

    public function testTheSizeLeavesOutTheRecipients(): void
    {
        $one = PushMessage::to(self::TOKEN_A)->title('Hi');
        $many = PushMessage::to([self::TOKEN_A, self::TOKEN_B, self::TOKEN_C])->title('Hi');

        self::assertSame($one->sizeInBytes(), $many->sizeInBytes());
    }

    public function testTheSizeCheckRaisesAboveTheLimit(): void
    {
        $message = PushMessage::to(self::TOKEN_A)->data(['blob' => str_repeat('x', 5000)]);

        try {
            $message->assertWithinSizeLimit();
            self::fail('The check must raise MessageTooLargeException.');
        } catch (MessageTooLargeException $exception) {
            self::assertSame(PushMessage::MAX_SIZE, $exception->limit);
            self::assertGreaterThan(5000, $exception->size);
        }
    }

    public function testAnEmptyRecipientListIsRejected(): void
    {
        $this->expectException(InvalidMessageException::class);
        $this->expectExceptionMessage('at least one recipient');

        self::ignoreResult(PushMessage::to([]));
    }

    public function testABadTokenIsRejected(): void
    {
        $this->expectException(InvalidTokenException::class);

        self::ignoreResult(PushMessage::to('not-a-token'));
    }

    public function testABadEnumValueNamesTheValidOnes(): void
    {
        $this->expectException(InvalidMessageException::class);
        $this->expectExceptionMessage('default, normal, high');

        self::ignoreResult(PushMessage::to(self::TOKEN_A)->priority('urgent'));
    }

    public function testANegativeBadgeIsRejected(): void
    {
        $this->expectException(InvalidMessageException::class);

        self::ignoreResult(PushMessage::to(self::TOKEN_A)->badge(-1));
    }

    public function testANegativeTtlIsRejected(): void
    {
        $this->expectException(InvalidMessageException::class);

        self::ignoreResult(PushMessage::to(self::TOKEN_A)->ttl(-1));
    }

    public function testARelevanceScoreOutOfRangeIsRejected(): void
    {
        $this->expectException(InvalidMessageException::class);

        self::ignoreResult(PushMessage::to(self::TOKEN_A)->relevanceScore(1.5));
    }

    public function testANonFiniteRelevanceScoreIsRejected(): void
    {
        $this->expectException(InvalidMessageException::class);
        $this->expectExceptionMessage('finite');

        self::ignoreResult(PushMessage::to(self::TOKEN_A)->relevanceScore(NAN));
    }

    public function testAnExpirationAcceptsADateTime(): void
    {
        $date = new DateTimeImmutable('@1700000000');

        self::assertSame(1_700_000_000, PushMessage::to(self::TOKEN_A)->expiration($date)->expiration);
    }

    public function testAnyTimestampIsAllowed(): void
    {
        self::assertSame(0, PushMessage::to(self::TOKEN_A)->expiration(0)->expiration);
        self::assertSame(-1, PushMessage::to(self::TOKEN_A)->expiration(-1)->expiration);
    }

    public function testWithDatumKeepsTheOtherKeys(): void
    {
        $message = PushMessage::to(self::TOKEN_A)->data(['a' => 1])->withDatum('b', 2);

        self::assertSame(['a' => 1, 'b' => 2], $message->data);
    }

    public function testWithDatumWorksOnAStdClass(): void
    {
        $data = new stdClass();
        $data->a = 1;

        $message = PushMessage::to(self::TOKEN_A)->data($data)->withDatum('b', 2);

        self::assertStringContainsString('"b":2', json_encode($message->toExpoArray(), JSON_THROW_ON_ERROR));
    }

    public function testPerRecipientSplitsTheMessage(): void
    {
        $messages = PushMessage::to([self::TOKEN_A, self::TOKEN_B])->title('Hi')->perRecipient();

        self::assertCount(2, $messages);
        self::assertSame([self::TOKEN_A], self::values($messages[0]->to));
        self::assertSame('Hi', $messages[1]->title);
    }

    public function testTheMessageNeverChanges(): void
    {
        $first = PushMessage::to(self::TOKEN_A)->title('One');
        $second = $first->title('Two');

        self::assertSame('One', $first->title);
        self::assertSame('Two', $second->title);
        self::assertNotSame($first, $second);
    }

    public function testATokenObjectIsAccepted(): void
    {
        $message = PushMessage::to(new PushToken(self::TOKEN_A));

        self::assertSame([self::TOKEN_A], self::values($message->to));
    }

    public function testAnItemThatIsNotATokenIsRejected(): void
    {
        $this->expectException(InvalidMessageException::class);
        $this->expectExceptionMessage('PushToken or a string');

        /** @phpstan-ignore argument.type */
        self::ignoreResult(PushMessage::to([42]));
    }
}
