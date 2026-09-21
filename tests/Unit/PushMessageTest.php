<?php

declare(strict_types=1);

namespace Expo\Push\Tests\Unit;

use DateTimeImmutable;
use Expo\Push\Exception\InvalidMessageException;
use Expo\Push\InterruptionLevel;
use Expo\Push\Priority;
use Expo\Push\PushMessage;
use Expo\Push\PushToken;
use Expo\Push\Sound;
use PHPUnit\Framework\TestCase;

final class PushMessageTest extends TestCase
{
    private const TOKEN_A = 'ExponentPushToken[aaaaaaaaaaaaaaaaaaaaaa]';

    private const TOKEN_B = 'ExponentPushToken[bbbbbbbbbbbbbbbbbbbbbb]';

    public function testItBuildsTheMinimalPayload(): void
    {
        $message = PushMessage::to(self::TOKEN_A)->title('Hello')->body('World');

        self::assertSame(
            ['to' => self::TOKEN_A, 'title' => 'Hello', 'body' => 'World'],
            $message->jsonSerialize()
        );
    }

    public function testItKeepsSeveralRecipientsAsAList(): void
    {
        $message = PushMessage::to([self::TOKEN_A, self::TOKEN_B])->title('Hello');

        self::assertSame([self::TOKEN_A, self::TOKEN_B], $message->jsonSerialize()['to']);
        self::assertSame(2, $message->recipientCount());
    }

    public function testItRemovesDuplicateRecipients(): void
    {
        $message = PushMessage::to([self::TOKEN_A, self::TOKEN_A, self::TOKEN_B]);

        self::assertSame(2, $message->recipientCount());
    }

    public function testItNeedsAtLeastOneRecipient(): void
    {
        $this->expectException(InvalidMessageException::class);

        $message = PushMessage::to([]);

        self::fail(sprintf('The call must fail. It returned %d recipients.', $message->recipientCount()));
    }

    public function testItNeverChangesTheOriginalMessage(): void
    {
        $first = PushMessage::to(self::TOKEN_A)->title('First');
        $second = $first->title('Second');

        self::assertSame('First', $first->title);
        self::assertSame('Second', $second->title);
        self::assertNotSame($first, $second);
    }

    public function testItBuildsEveryField(): void
    {
        $message = new PushMessage(
            to: self::TOKEN_A,
            title: 'Title',
            body: 'Body',
            data: ['orderId' => 42],
            subtitle: 'Subtitle',
            sound: Sound::critical('bells.wav', 0.5),
            ttl: 600,
            expiration: 1893456000,
            priority: 'high',
            interruptionLevel: 'time-sensitive',
            badge: 3,
            channelId: 'orders',
            icon: 'ic_notification',
            image: 'https://example.com/map.png',
            categoryId: 'order_actions',
            mutableContent: true,
            contentAvailable: true,
            collapseId: 'order-42',
            tag: 'order-42',
            threadId: 'orders',
            targetContentId: 'window-1',
            relevanceScore: 0.9,
            filterCriteria: 'orders',
        );

        self::assertSame([
            'to' => self::TOKEN_A,
            'title' => 'Title',
            'subtitle' => 'Subtitle',
            'body' => 'Body',
            'data' => ['orderId' => 42],
            'sound' => ['critical' => true, 'name' => 'bells.wav', 'volume' => 0.5],
            'ttl' => 600,
            'expiration' => 1893456000,
            'priority' => 'high',
            'interruptionLevel' => 'time-sensitive',
            'badge' => 3,
            'channelId' => 'orders',
            'icon' => 'ic_notification',
            'richContent' => ['image' => 'https://example.com/map.png'],
            'categoryId' => 'order_actions',
            'mutableContent' => true,
            'contentAvailable' => true,
            'collapseId' => 'order-42',
            'tag' => 'order-42',
            'threadId' => 'orders',
            'targetContentId' => 'window-1',
            'relevanceScore' => 0.9,
            'filterCriteria' => 'orders',
        ], $message->jsonSerialize());
    }

    public function testItKeepsTheNullSoundForASilentNotification(): void
    {
        $payload = PushMessage::to(self::TOKEN_A)->silent()->jsonSerialize();

        self::assertArrayHasKey('sound', $payload);
        self::assertNull($payload['sound']);
    }

    public function testItAcceptsEnumsAndStrings(): void
    {
        $fromString = PushMessage::to(self::TOKEN_A)->priority('high')->interruptionLevel('passive');
        $fromEnum = PushMessage::to(self::TOKEN_A)
            ->priority(Priority::High)
            ->interruptionLevel(InterruptionLevel::Passive);

        self::assertSame(Priority::High, $fromString->priority);
        self::assertSame(InterruptionLevel::Passive, $fromString->interruptionLevel);
        self::assertEquals($fromEnum->jsonSerialize(), $fromString->jsonSerialize());
    }

    public function testItRejectsAnUnknownPriority(): void
    {
        $this->expectException(InvalidMessageException::class);
        $this->expectExceptionMessage('"urgent" is not a valid value.');

        $message = PushMessage::to(self::TOKEN_A)->priority('urgent');

        self::fail(sprintf('The call must fail. It returned the priority %s.', $message->priority?->value));
    }

    public function testItRejectsValuesOutOfRange(): void
    {
        $this->expectException(InvalidMessageException::class);

        $message = PushMessage::to(self::TOKEN_A)->relevanceScore(1.5);

        self::fail(sprintf('The call must fail. It returned the score %s.', $message->relevanceScore));
    }

    public function testItAcceptsADateTimeForTheExpiration(): void
    {
        $date = new DateTimeImmutable('2030-01-01 00:00:00 UTC');
        $message = PushMessage::to(self::TOKEN_A)->expiration($date);

        self::assertSame($date->getTimestamp(), $message->expiration);
    }

    public function testItAddsOneDataKeyAtATime(): void
    {
        $message = PushMessage::to(self::TOKEN_A)
            ->data(['a' => 1])
            ->withDatum('b', 2);

        self::assertSame(['a' => 1, 'b' => 2], $message->data);
    }

    public function testItAddsAndReplacesRecipients(): void
    {
        $message = PushMessage::to(self::TOKEN_A)->addRecipients(self::TOKEN_B);
        self::assertSame(2, $message->recipientCount());

        $replaced = $message->recipients(self::TOKEN_B);
        self::assertSame([self::TOKEN_B], array_map(
            static fn (PushToken $token): string => $token->value,
            $replaced->to
        ));
    }

    public function testItSplitsIntoOneMessagePerRecipient(): void
    {
        $messages = PushMessage::to([self::TOKEN_A, self::TOKEN_B])->title('Hi')->perRecipient();

        self::assertCount(2, $messages);
        self::assertSame(self::TOKEN_A, $messages[0]->jsonSerialize()['to']);
        self::assertSame('Hi', $messages[1]->title);
    }

    public function testItMeasuresTheSizeWithoutTheRecipients(): void
    {
        $small = PushMessage::to(self::TOKEN_A)->title('Hi');
        $large = PushMessage::to(self::TOKEN_A)->data(['blob' => str_repeat('x', 5000)]);

        self::assertLessThan(PushMessage::MAX_SIZE, $small->sizeInBytes());
        self::assertGreaterThan(PushMessage::MAX_SIZE, $large->sizeInBytes());
    }
}
