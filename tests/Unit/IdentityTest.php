<?php

declare(strict_types=1);

namespace Expo\Push\Tests\Unit;

use Expo\Push\Exception\InvalidMessageException;
use Expo\Push\Plan\PlannedNotification;
use Expo\Push\Plan\Planner;
use Expo\Push\PushMessage;
use Expo\Push\Result\NotificationOutcome;
use Expo\Push\Tests\Support\FakeHttpClient;
use Expo\Push\Tests\Support\TestCase;
use Generator;

/**
 * A notification is one message and one device. It is not a unique token and it
 * is not a message object.
 */
final class IdentityTest extends TestCase
{
    public function testTheSameTokenInTwoMessagesStaysTwoNotifications(): void
    {
        $http = (new FakeHttpClient())->queue(['data' => [
            ['status' => 'ok', 'id' => 'r1'],
            ['status' => 'ok', 'id' => 'r2'],
        ]]);

        $result = $this->expo($http)->send([
            PushMessage::to(self::TOKEN_A)->title('One')->reference('one'),
            PushMessage::to(self::TOKEN_A)->title('Two')->reference('two'),
        ]);

        self::assertSame(2, $result->count());
        self::assertSame('r1', $result->outcomes()[0]->receiptId());
        self::assertSame('r2', $result->outcomes()[1]->receiptId());
        self::assertSame('one', $result->outcomes()[0]->reference);
        self::assertSame('two', $result->outcomes()[1]->reference);
        self::assertSame(0, $result->outcomes()[0]->messageKey);
        self::assertSame(1, $result->outcomes()[1]->messageKey);
        self::assertCount(2, $http->payload());
    }

    public function testARepeatedTokenInsideOneMessageIsOneNotification(): void
    {
        $message = PushMessage::to([self::TOKEN_A, self::TOKEN_B, self::TOKEN_A]);

        self::assertSame(2, $message->recipientCount());
        self::assertSame([self::TOKEN_A, self::TOKEN_B], self::values($message->to));
    }

    public function testTheFirstPositionOfARepeatedTokenWins(): void
    {
        $message = PushMessage::to([self::TOKEN_B, self::TOKEN_A, self::TOKEN_B, self::TOKEN_C]);

        self::assertSame([self::TOKEN_B, self::TOKEN_A, self::TOKEN_C], self::values($message->to));
    }

    public function testAddRecipientsKeepsTheFirstPosition(): void
    {
        $message = PushMessage::to([self::TOKEN_A, self::TOKEN_B])->addRecipients([self::TOKEN_A, self::TOKEN_C]);

        self::assertSame([self::TOKEN_A, self::TOKEN_B, self::TOKEN_C], self::values($message->to));
    }

    public function testThePlanKeepsTheMessageKeyOfAnAssociativeInput(): void
    {
        $plan = Planner::plan([
            'welcome' => PushMessage::to([self::TOKEN_A, self::TOKEN_B]),
            'reminder' => PushMessage::to(self::TOKEN_C),
        ]);

        self::assertSame(3, $plan->count());
        self::assertSame(
            [['welcome', 0], ['welcome', 1], ['reminder', 0]],
            array_map(
                static fn (PlannedNotification $item): array => [$item->messageKey, $item->recipientIndex],
                $plan->notifications
            )
        );
        self::assertSame([0, 1, 2], array_map(
            static fn (PlannedNotification $item): int => $item->index,
            $plan->notifications
        ));
    }

    public function testAGeneratorWorksAsInput(): void
    {
        $http = (new FakeHttpClient())->queue(['data' => self::okTickets(3)]);

        $messages = static function (): Generator {
            yield PushMessage::to(self::TOKEN_A);
            yield PushMessage::to([self::TOKEN_B, self::TOKEN_C]);
        };

        $result = $this->expo($http)->send($messages());

        self::assertSame(3, $result->count());
        self::assertSame(self::TOKEN_C, $result->outcomes()[2]->token->value);
    }

    public function testTwoMessagesWithOneReferenceRaiseBeforeAnyRequest(): void
    {
        $http = new FakeHttpClient();

        try {
            self::ignoreResult($this->expo($http)->send([
                PushMessage::to(self::TOKEN_A)->reference('same'),
                PushMessage::to(self::TOKEN_B)->reference('same'),
            ]));
            self::fail('The send must raise InvalidMessageException.');
        } catch (InvalidMessageException $exception) {
            self::assertStringContainsString('reference "same"', $exception->getMessage());
        }

        self::assertSame(0, $http->requestCount());
    }

    public function testOneReferenceCoversEveryRecipientOfOneMessage(): void
    {
        $plan = Planner::plan(PushMessage::to([self::TOKEN_A, self::TOKEN_B])->reference('batch-1'));

        self::assertSame(
            ['batch-1', 'batch-1'],
            array_map(static fn (PlannedNotification $item): ?string => $item->reference, $plan->notifications)
        );
        self::assertNotSame($plan->notifications[0]->token, $plan->notifications[1]->token);
    }

    public function testTheReferenceNeverGoesToExpo(): void
    {
        $http = (new FakeHttpClient())->queue(['data' => self::okTickets(1)]);

        $result = $this->expo($http)->send(PushMessage::to(self::TOKEN_A)->reference('secret-correlation'));

        self::assertCount(1, $result->accepted());
        self::assertStringNotContainsString('secret-correlation', $http->requests[0]->body);
    }

    public function testTheIdentityHoldsThroughAChunkSplit(): void
    {
        $http = new FakeHttpClient();
        $http->queue(['data' => self::okTickets(2, 'a')]);
        $http->queue(['data' => self::okTickets(2, 'b')]);

        $result = $this->expo($http, sendChunkSize: 2)->send([
            PushMessage::to([self::TOKEN_A, self::TOKEN_B])->reference('first'),
            PushMessage::to([self::TOKEN_C, self::tokens(1, 50)[0]])->reference('second'),
        ]);

        self::assertSame(['first', 'first', 'second', 'second'], array_map(
            static fn (NotificationOutcome $outcome): ?string => $outcome->reference,
            $result->outcomes()
        ));
        self::assertSame(['a0', 'a1', 'b0', 'b1'], array_map(
            static fn (NotificationOutcome $outcome): ?string => $outcome->receiptId(),
            $result->outcomes()
        ));
    }

    public function testAChunkSplitsOneMessageAcrossTwoRequestsAndKeepsTheOrder(): void
    {
        $http = new FakeHttpClient();
        $http->queue(['data' => self::okTickets(2, 'a')]);
        $http->queue(['data' => self::okTickets(1, 'b')]);

        $result = $this->expo($http, sendChunkSize: 2)->send(PushMessage::to(self::tokens(3))->title('Hi'));

        self::assertSame(2, $http->requestCount());
        self::assertCount(1, $http->payload(0));
        self::assertSame(self::tokens(2), $http->payload(0)[0]['to']);
        self::assertSame(self::tokens(1, 2)[0], $http->payload(1)[0]['to']);
        self::assertSame(self::tokens(1, 2)[0], $result->outcomes()[2]->token->value);
    }

    public function testASingleRecipientGoesOutAsAStringAndManyAsAList(): void
    {
        $http = (new FakeHttpClient())->queue(['data' => self::okTickets(3)]);

        $result = $this->expo($http)->send([
            PushMessage::to(self::TOKEN_A),
            PushMessage::to([self::TOKEN_B, self::TOKEN_C]),
        ]);

        self::assertCount(3, $result->accepted());
        self::assertSame(self::TOKEN_A, $http->payload()[0]['to']);
        self::assertSame([self::TOKEN_B, self::TOKEN_C], $http->payload()[1]['to']);
    }

    public function testThePayloadIsSharedBetweenTheRecipientsOfOneMessage(): void
    {
        $http = (new FakeHttpClient())->queue(['data' => self::okTickets(3)]);

        $result = $this->expo($http)->send(PushMessage::to(self::tokens(3))->title('Shared')->body('One body'));

        self::assertCount(3, $result->accepted());
        // One message object on the wire, not one for each device.
        self::assertCount(1, $http->payload());
        self::assertSame('Shared', $http->payload()[0]['title']);
    }
}
