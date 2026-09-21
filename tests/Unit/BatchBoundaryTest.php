<?php

declare(strict_types=1);

namespace Expo\Push\Tests\Unit;

use Expo\Push\Exception\InvalidConfigurationException;
use Expo\Push\Expo;
use Expo\Push\Plan\Planner;
use Expo\Push\PushMessage;
use Expo\Push\Tests\Support\FakeHttpClient;
use Expo\Push\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class BatchBoundaryTest extends TestCase
{
    /**
     * @return iterable<string, array{0: int, 1: list<int>}>
     */
    public static function sendSizes(): iterable
    {
        yield '0 notifications' => [0, []];
        yield '1 notification' => [1, [1]];
        yield '99 notifications' => [99, [99]];
        yield '100 notifications' => [100, [100]];
        yield '101 notifications' => [101, [100, 1]];
        yield '250 notifications' => [250, [100, 100, 50]];
    }

    /**
     * @param list<int> $expected
     */
    #[DataProvider('sendSizes')]
    public function testASendSplitsAtOneHundredNotifications(int $count, array $expected): void
    {
        $http = new FakeHttpClient();

        foreach ($expected as $size) {
            $http->queue(['data' => self::okTickets($size)]);
        }

        $messages = $count === 0 ? [] : [PushMessage::to(self::tokens($count))->title('Hi')];
        $result = $this->expo($http)->send($messages);

        self::assertSame(count($expected), $http->requestCount());
        self::assertSame($count, $result->count());
        self::assertCount($count, $result->accepted());

        foreach ($expected as $index => $size) {
            $payload = $http->payload($index);
            $to = $payload[0]['to'];
            self::assertSame($size, is_array($to) ? count($to) : 1);
        }
    }

    /**
     * @return iterable<string, array{0: int, 1: list<int>}>
     */
    public static function receiptSizes(): iterable
    {
        yield '0 ids' => [0, []];
        yield '1 id' => [1, [1]];
        yield '999 ids' => [999, [999]];
        yield '1000 ids' => [1000, [1000]];
        yield '1001 ids' => [1001, [1000, 1]];
    }

    /**
     * @param list<int> $expected
     */
    #[DataProvider('receiptSizes')]
    public function testALookupSplitsAtOneThousandIds(int $count, array $expected): void
    {
        $http = new FakeHttpClient();

        foreach ($expected as $ignored) {
            $http->queue(['data' => []]);
        }

        $ids = $count === 0 ? [] : array_map(static fn (int $index): string => 'r' . $index, range(1, $count));
        $result = $this->expo($http)->receipts($ids);

        self::assertSame(count($expected), $http->requestCount());
        self::assertSame($count, $result->count());

        foreach ($expected as $index => $size) {
            self::assertCount($size, $http->payload($index)['ids']);
        }
    }

    public function testAnOversizedMultiRecipientMessageSplitsAcrossRequests(): void
    {
        $http = new FakeHttpClient();
        $http->queue(['data' => self::okTickets(100)]);
        $http->queue(['data' => self::okTickets(100)]);
        $http->queue(['data' => self::okTickets(50)]);

        $result = $this->expo($http)->send(PushMessage::to(self::tokens(250))->title('Broadcast'));

        self::assertSame(3, $http->requestCount());
        self::assertCount(250, $result->accepted());
        self::assertCount(50, $http->payload(2)[0]['to']);
    }

    public function testTheLastChunkRangeShowsItsRealLength(): void
    {
        $http = new FakeHttpClient();
        $http->queue(['data' => self::okTickets(100)]);
        $http->queue(['data' => self::okTickets(100)]);
        $http->queueRaw('boom', 400);

        $result = $this->expo($http, continueAfterFailure: true)
            ->send(PushMessage::to(self::tokens(250))->title('Hi'));

        $failure = $result->requestFailures()[0];

        self::assertSame([200, 249], $failure->indexRange());
        self::assertSame(50, $failure->size());
        self::assertCount(50, $failure->indexes);
    }

    public function testTheChunkHelperSplitsWithoutSending(): void
    {
        $chunks = Expo::chunk([
            PushMessage::to(self::tokens(100)),
            PushMessage::to(self::TOKEN_A),
        ]);

        self::assertCount(2, $chunks);
        self::assertSame(100, $chunks[0][0]->recipientCount());
        self::assertSame(1, $chunks[1][0]->recipientCount());
    }

    public function testTheLazyChunkHelperYieldsOneChunkAtATime(): void
    {
        $seen = [];

        foreach (Expo::lazyChunks(PushMessage::to(self::tokens(250)), 100) as $number => $chunk) {
            $seen[$number] = array_sum(array_map(
                static fn (PushMessage $message): int => $message->recipientCount(),
                $chunk
            ));
        }

        self::assertSame([100, 100, 50], $seen);
    }

    public function testAChunkLimitBelowOneRaises(): void
    {
        $this->expectException(\Expo\Push\Exception\InvalidMessageException::class);

        Expo::chunk(PushMessage::to(self::TOKEN_A), 0);
    }

    public function testASendChunkSizeAboveTheServiceMaximumRaises(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('between 1 and 100');

        new Expo(httpClient: new FakeHttpClient(), sendChunkSize: 101);
    }

    public function testAReceiptChunkSizeAboveTheServiceMaximumRaises(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('between 1 and 1000');

        new Expo(httpClient: new FakeHttpClient(), receiptChunkSize: 1001);
    }

    public function testThePlanCountsRecipientsAndNotMessages(): void
    {
        $plan = Planner::plan([
            PushMessage::to(self::tokens(60)),
            PushMessage::to(self::tokens(60, 100)),
        ]);

        self::assertSame(120, $plan->count());

        $chunks = $plan->chunks(100);

        self::assertCount(2, $chunks);
        self::assertSame(100, $chunks[0]->count());
        self::assertSame(20, $chunks[1]->count());
        // The first request holds two message objects: 60 devices and 40 devices.
        self::assertCount(2, $chunks[0]->body);
        self::assertCount(1, $chunks[1]->body);
    }
}
