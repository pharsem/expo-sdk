<?php

declare(strict_types=1);

namespace Expo\Push\Tests\Unit;

use Expo\Push\Expo;
use Expo\Push\PushError;
use Expo\Push\PushMessage;
use Expo\Push\PushToken;
use Expo\Push\Tests\Support\FakeHttpClient;
use PHPUnit\Framework\TestCase;

final class ExpoReceiptsTest extends TestCase
{
    private const TOKEN_A = 'ExponentPushToken[aaaaaaaaaaaaaaaaaaaaaa]';

    private const TOKEN_B = 'ExponentPushToken[bbbbbbbbbbbbbbbbbbbbbb]';

    public function testItReadsReceiptsFromTicketIds(): void
    {
        $http = (new FakeHttpClient())->queue(['data' => [
            'ticket-1' => ['status' => 'ok'],
            'ticket-2' => [
                'status' => 'error',
                'message' => 'not registered',
                'details' => ['error' => 'DeviceNotRegistered'],
            ],
        ]]);

        $receipts = $this->expo($http)->receipts(['ticket-1', 'ticket-2']);

        self::assertCount(2, $receipts);
        self::assertSame('https://exp.host/--/api/v2/push/getReceipts', $http->requests[0]['url']);
        self::assertSame(['ids' => ['ticket-1', 'ticket-2']], $http->payload());

        $failed = $receipts->get('ticket-2');

        self::assertTrue($receipts->get('ticket-1')?->isOk());
        self::assertNotNull($failed);
        self::assertTrue($failed->isDeviceNotRegistered());
        self::assertSame(PushError::DeviceNotRegistered, $failed->error);
        self::assertTrue($receipts->hasErrors());
        self::assertCount(1, $receipts->ok());
    }

    public function testItCarriesTheDeviceTokenFromTheTicketToTheReceipt(): void
    {
        $http = new FakeHttpClient();
        $http->queue(['data' => [
            ['status' => 'ok', 'id' => 'ticket-1'],
            ['status' => 'ok', 'id' => 'ticket-2'],
        ]]);
        $http->queue(['data' => [
            'ticket-1' => ['status' => 'ok'],
            'ticket-2' => [
                'status' => 'error',
                'message' => 'not registered',
                'details' => ['error' => 'DeviceNotRegistered'],
            ],
        ]]);

        $expo = $this->expo($http);
        $tickets = $expo->send(PushMessage::to([self::TOKEN_A, self::TOKEN_B]));
        $receipts = $expo->receipts($tickets);

        self::assertSame(self::TOKEN_A, $receipts->get('ticket-1')?->token?->value);
        self::assertSame(
            [self::TOKEN_B],
            array_map(static fn (PushToken $token): string => $token->value, $receipts->unregisteredTokens())
        );
    }

    public function testItReadsTheTokenFromTheDetailsOfExpo(): void
    {
        $http = (new FakeHttpClient())->queue(['data' => [
            'ticket-1' => [
                'status' => 'error',
                'message' => 'not registered',
                'details' => ['error' => 'DeviceNotRegistered', 'expoPushToken' => self::TOKEN_A],
            ],
        ]]);

        $receipts = $this->expo($http)->receipts('ticket-1');

        self::assertSame(self::TOKEN_A, $receipts->get('ticket-1')?->token?->value);
    }

    public function testItReportsTheIdsThatExpoDidNotAnswer(): void
    {
        $http = (new FakeHttpClient())->queue(['data' => ['ticket-1' => ['status' => 'ok']]]);

        $receipts = $this->expo($http)->receipts(['ticket-1', 'ticket-2']);

        self::assertSame(['ticket-2'], $receipts->pendingIds());
    }

    public function testItSplitsMoreThanThreeHundredIdsIntoTwoRequests(): void
    {
        $ids = array_map(static fn (int $index): string => 'ticket-' . $index, range(1, 320));
        $http = new FakeHttpClient();
        $http->queue(['data' => []]);
        $http->queue(['data' => []]);

        $receipts = $this->expo($http)->receipts($ids);

        self::assertCount(0, $receipts);
        self::assertSame(2, $http->requestCount());
        self::assertCount(300, $http->payload(0)['ids']);
        self::assertCount(20, $http->payload(1)['ids']);
    }

    public function testItAsksForEachIdOneTime(): void
    {
        $http = (new FakeHttpClient())->queue(['data' => []]);

        $receipts = $this->expo($http)->receipts(['ticket-1', 'ticket-1', 'ticket-2']);

        self::assertCount(0, $receipts);
        self::assertSame(['ids' => ['ticket-1', 'ticket-2']], $http->payload());
    }

    public function testItSendsNothingForAnEmptyList(): void
    {
        $http = new FakeHttpClient();

        $receipts = $this->expo($http)->receipts([]);

        self::assertTrue($receipts->isEmpty());
        self::assertSame(0, $http->requestCount());
    }

    private function expo(FakeHttpClient $http): Expo
    {
        return new Expo(httpClient: $http, maxRetries: 0, retryDelayMs: 0);
    }
}
