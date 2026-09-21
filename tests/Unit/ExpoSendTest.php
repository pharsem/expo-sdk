<?php

declare(strict_types=1);

namespace Expo\Push\Tests\Unit;

use Expo\Push\Exception\ExpoApiException;
use Expo\Push\Exception\MessageTooLargeException;
use Expo\Push\Exception\RateLimitException;
use Expo\Push\Exception\TransportException;
use Expo\Push\Expo;
use Expo\Push\PushError;
use Expo\Push\PushMessage;
use Expo\Push\PushToken;
use Expo\Push\Tests\Support\FakeHttpClient;
use PHPUnit\Framework\TestCase;

final class ExpoSendTest extends TestCase
{
    private const TOKEN_A = 'ExponentPushToken[aaaaaaaaaaaaaaaaaaaaaa]';

    private const TOKEN_B = 'ExponentPushToken[bbbbbbbbbbbbbbbbbbbbbb]';

    public function testItSendsOneMessageAndReadsTheTicket(): void
    {
        $http = (new FakeHttpClient())->queue(['data' => [['status' => 'ok', 'id' => 'ticket-1']]]);
        $expo = $this->expo($http);

        $tickets = $expo->send(PushMessage::to(self::TOKEN_A)->title('Hello'));

        $ticket = $tickets->first();

        self::assertCount(1, $tickets);
        self::assertNotNull($ticket);
        self::assertTrue($ticket->isOk());
        self::assertSame('ticket-1', $ticket->id);
        self::assertSame(self::TOKEN_A, $ticket->token?->value);
        self::assertSame(['ticket-1'], $tickets->ids());
        self::assertFalse($tickets->hasErrors());

        self::assertSame('https://exp.host/--/api/v2/push/send', $http->requests[0]['url']);
        self::assertSame([['to' => self::TOKEN_A, 'title' => 'Hello']], $http->payload());
    }

    public function testTheNotifyShortcutSendsTitleBodyAndData(): void
    {
        $http = (new FakeHttpClient())->queue(['data' => [['status' => 'ok', 'id' => 'ticket-1']]]);

        $this->expo($http)->notify(self::TOKEN_A, 'Hello', 'World', ['orderId' => 42]);

        self::assertSame(
            [['to' => self::TOKEN_A, 'title' => 'Hello', 'body' => 'World', 'data' => ['orderId' => 42]]],
            $http->payload()
        );
    }

    public function testItMapsEveryTicketBackToItsDevice(): void
    {
        $http = (new FakeHttpClient())->queue(['data' => [
            ['status' => 'ok', 'id' => 'ticket-1'],
            ['status' => 'error', 'message' => 'gone', 'details' => ['error' => 'DeviceNotRegistered']],
        ]]);

        $tickets = $this->expo($http)->send(PushMessage::to([self::TOKEN_A, self::TOKEN_B])->title('Hi'));

        self::assertSame(self::TOKEN_A, $tickets->all()[0]->token?->value);
        self::assertSame(self::TOKEN_B, $tickets->all()[1]->token?->value);
        self::assertTrue($tickets->hasErrors());
        self::assertSame(PushError::DeviceNotRegistered, $tickets->all()[1]->error);
        self::assertTrue($tickets->all()[1]->isDeviceNotRegistered());
        self::assertSame(
            [self::TOKEN_B],
            array_map(static fn (PushToken $token): string => $token->value, $tickets->unregisteredTokens())
        );
        self::assertSame(['ticket-1'], $tickets->ids());
        self::assertCount(1, $tickets->ok());
        self::assertCount(1, $tickets->errors());
    }

    public function testItKeepsAnUnknownErrorCodeAsAString(): void
    {
        $http = (new FakeHttpClient())->queue(['data' => [
            ['status' => 'error', 'message' => 'boom', 'details' => ['error' => 'SomethingNew']],
        ]]);

        $ticket = $this->expo($http)->send(PushMessage::to(self::TOKEN_A))->first();

        self::assertNotNull($ticket);
        self::assertSame('SomethingNew', $ticket->errorCode);
        self::assertNull($ticket->error);
        self::assertSame('boom', $ticket->message);
    }

    public function testItSplitsMoreThanOneHundredDevicesIntoTwoRequests(): void
    {
        $tokens = self::tokens(150);
        $http = new FakeHttpClient();
        $http->queue(['data' => self::okTickets(100)]);
        $http->queue(['data' => self::okTickets(50)]);

        $tickets = $this->expo($http)->send(PushMessage::to($tokens)->title('Hi'));

        self::assertSame(2, $http->requestCount());
        self::assertCount(150, $tickets);

        $first = $http->payload(0);
        self::assertCount(1, $first);
        self::assertCount(100, $first[0]['to']);

        $second = $http->payload(1);
        self::assertCount(50, $second[0]['to']);
        self::assertSame($tokens[149], $second[0]['to'][49]);
        self::assertSame($tokens[149], $tickets->all()[149]->token?->value);
    }

    public function testItPacksSeveralMessagesIntoOneRequest(): void
    {
        $http = (new FakeHttpClient())->queue(['data' => self::okTickets(2)]);

        $this->expo($http)->send([
            PushMessage::to(self::TOKEN_A)->title('One'),
            PushMessage::to(self::TOKEN_B)->title('Two'),
        ]);

        self::assertSame(1, $http->requestCount());
        self::assertCount(2, $http->payload());
    }

    public function testChunkSplitsWithoutSending(): void
    {
        $chunks = Expo::chunk([
            PushMessage::to(self::tokens(100)),
            PushMessage::to(self::TOKEN_A),
        ]);

        self::assertCount(2, $chunks);
        self::assertSame(100, $chunks[0][0]->recipientCount());
        self::assertSame(1, $chunks[1][0]->recipientCount());
    }

    public function testItSendsTheAccessTokenAndTheStandardHeaders(): void
    {
        $http = (new FakeHttpClient())->queue(['data' => self::okTickets(1)]);
        $expo = new Expo(accessToken: 'secret', httpClient: $http, maxRetries: 0);

        $expo->send(PushMessage::to(self::TOKEN_A));

        $headers = $http->headers();
        self::assertSame('Bearer secret', $headers['authorization']);
        self::assertSame('application/json', $headers['content-type']);
        self::assertSame('application/json', $headers['accept']);
        self::assertStringStartsWith('expo-sdk-php/', $headers['user-agent']);
    }

    public function testItCompressesALargeBody(): void
    {
        $http = (new FakeHttpClient())->queue(['data' => self::okTickets(1)]);

        $this->expo($http)->send(PushMessage::to(self::TOKEN_A)->body(str_repeat('a', 2000)));

        self::assertSame('gzip', $http->headers()['content-encoding'] ?? null);
        self::assertCount(1, $http->payload());
    }

    public function testItRefusesAMessageAboveTheSizeLimit(): void
    {
        $http = new FakeHttpClient();

        $this->expectException(MessageTooLargeException::class);

        $this->expo($http)->send(PushMessage::to(self::TOKEN_A)->data(['blob' => str_repeat('x', 5000)]));
    }

    public function testItRetriesAfterARateLimitAnswer(): void
    {
        $http = new FakeHttpClient();
        $http->queue(['errors' => [['code' => 'TOO_MANY_REQUESTS', 'message' => 'slow down']]], 429);
        $http->queue(['data' => self::okTickets(1)]);

        $tickets = $this->expo($http)->send(PushMessage::to(self::TOKEN_A));

        self::assertSame(2, $http->requestCount());
        self::assertCount(1, $tickets);
    }

    public function testItGivesUpAfterTheLastRetry(): void
    {
        $http = new FakeHttpClient();
        $http->queue(['errors' => [['code' => 'TOO_MANY_REQUESTS', 'message' => 'slow down']]], 429);
        $http->queue(['errors' => [['code' => 'TOO_MANY_REQUESTS', 'message' => 'slow down']]], 429);

        $expo = new Expo(httpClient: $http, maxRetries: 1, retryDelayMs: 0);

        try {
            $expo->send(PushMessage::to(self::TOKEN_A));
            self::fail('The send must raise a RateLimitException.');
        } catch (RateLimitException $exception) {
            self::assertSame('TOO_MANY_REQUESTS', $exception->errorCode);
            self::assertSame(429, $exception->status);
            self::assertSame('slow down', $exception->getMessage());
        }

        self::assertSame(2, $http->requestCount());
    }

    public function testItRaisesTheApiErrorOfExpo(): void
    {
        $http = (new FakeHttpClient())->queue([
            'errors' => [['code' => 'PUSH_TOO_MANY_EXPERIENCE_IDS', 'message' => 'two projects']],
        ], 400);

        $this->expectException(ExpoApiException::class);
        $this->expectExceptionMessage('two projects');

        $this->expo($http)->send(PushMessage::to(self::TOKEN_A));
    }

    public function testItRetriesAfterAServerError(): void
    {
        $http = new FakeHttpClient();
        $http->queueRaw('<html>502 Bad Gateway</html>', 502);
        $http->queue(['data' => self::okTickets(1)]);

        $tickets = $this->expo($http)->send(PushMessage::to(self::TOKEN_A));

        self::assertSame(2, $http->requestCount());
        self::assertCount(1, $tickets);
    }

    public function testItRaisesATransportErrorForABodyThatIsNotJson(): void
    {
        $http = (new FakeHttpClient())->queueRaw('<html>502 Bad Gateway</html>', 502);
        $expo = new Expo(httpClient: $http, maxRetries: 0);

        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('Expo answered with status 502');

        $expo->send(PushMessage::to(self::TOKEN_A));
    }

    public function testItSendsNothingForAnEmptyList(): void
    {
        $http = new FakeHttpClient();

        $tickets = $this->expo($http)->send([]);

        self::assertTrue($tickets->isEmpty());
        self::assertSame(0, $http->requestCount());
    }

    private function expo(FakeHttpClient $http): Expo
    {
        return new Expo(httpClient: $http, maxRetries: 2, retryDelayMs: 0);
    }

    /**
     * @return list<string>
     */
    private static function tokens(int $count): array
    {
        return array_map(
            static fn (int $index): string => sprintf('ExponentPushToken[%022d]', $index),
            range(0, $count - 1)
        );
    }

    /**
     * @return list<array{status: string, id: string}>
     */
    private static function okTickets(int $count): array
    {
        return array_map(
            static fn (int $index): array => ['status' => 'ok', 'id' => 'ticket-' . $index],
            range(0, $count - 1)
        );
    }
}
