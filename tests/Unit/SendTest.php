<?php

declare(strict_types=1);

namespace Expo\Push\Tests\Unit;

use Expo\Push\Exception\InvalidMessageException;
use Expo\Push\Exception\MessageTooLargeException;
use Expo\Push\Exception\SendFailedException;
use Expo\Push\Http\TransportFailureKind;
use Expo\Push\PushError;
use Expo\Push\PushMessage;
use Expo\Push\Result\Acceptance;
use Expo\Push\Result\FailureCategory;
use Expo\Push\Result\NotAcceptedReason;
use Expo\Push\Retry\NoRetryPolicy;
use Expo\Push\Tests\Support\FakeHttpClient;
use Expo\Push\Tests\Support\TestCase;
use Generator;

final class SendTest extends TestCase
{
    public function testItSendsOneMessageAndReportsAcceptance(): void
    {
        $http = (new FakeHttpClient())->queue(['data' => [['status' => 'ok', 'id' => 'ticket-1']]]);

        $result = $this->expo($http)->send(PushMessage::to(self::TOKEN_A)->title('Hello'));

        self::assertSame(1, $result->count());
        self::assertCount(1, $result->accepted());
        self::assertSame(Acceptance::Accepted, $result->outcomes()[0]->acceptance);
        self::assertSame('ticket-1', $result->outcomes()[0]->receiptId());
        self::assertSame(self::TOKEN_A, $result->outcomes()[0]->token->value);
        self::assertFalse($result->outcomes()[0]->duplicateRisk);
        self::assertTrue($result->isCompleteSuccess());
        self::assertFalse($result->needsAttention());

        self::assertSame('https://exp.host/--/api/v2/push/send', $http->requests[0]->url);
        self::assertSame([['title' => 'Hello', 'to' => self::TOKEN_A]], $http->payload());
    }

    public function testTheNotifyShortcutSendsTitleBodyAndData(): void
    {
        $http = (new FakeHttpClient())->queue(['data' => [['status' => 'ok', 'id' => 'ticket-1']]]);

        $result = $this->expo($http)->notify(self::TOKEN_A, 'Hello', 'World', ['orderId' => 42]);

        self::assertCount(1, $result->accepted());
        self::assertSame(
            [['title' => 'Hello', 'body' => 'World', 'data' => ['orderId' => 42], 'to' => self::TOKEN_A]],
            $http->payload()
        );
    }

    public function testAnEmptyInputSendsNothing(): void
    {
        $http = new FakeHttpClient();

        $result = $this->expo($http)->send([]);

        self::assertTrue($result->isEmpty());
        self::assertSame(0, $http->requestCount());
        self::assertTrue($result->isCompleteSuccess());
    }

    public function testAnErrorTicketIsDataAndDoesNotStopTheOperation(): void
    {
        $http = new FakeHttpClient();
        $http->queue(['data' => [
            ['status' => 'ok', 'id' => 'ticket-1'],
            ['status' => 'error', 'message' => 'gone', 'details' => ['error' => 'DeviceNotRegistered']],
        ]]);
        $http->queue(['data' => [['status' => 'ok', 'id' => 'ticket-3']]]);

        $result = $this->expo($http, sendChunkSize: 2)->send([
            PushMessage::to([self::TOKEN_A, self::TOKEN_B])->title('Hi'),
            PushMessage::to(self::TOKEN_C)->title('Hi'),
        ]);

        self::assertSame(2, $http->requestCount());
        self::assertSame(3, $result->count());
        self::assertCount(2, $result->accepted());
        self::assertCount(1, $result->notAccepted());
        self::assertSame(NotAcceptedReason::Rejected, $result->outcomes()[1]->reason);
        self::assertSame(PushError::DeviceNotRegistered, $result->outcomes()[1]->ticket?->error);
        self::assertSame([self::TOKEN_B], self::values($result->unregisteredTokens()));
        self::assertTrue($result->hasTicketErrors());
        self::assertFalse($result->hasRequestFailures());
        self::assertFalse($result->isCompleteSuccess());
    }

    public function testAnErrorTicketDoesNotRaiseFromThrowIfRequestFailed(): void
    {
        $http = (new FakeHttpClient())->queue(['data' => [
            ['status' => 'error', 'message' => 'gone', 'details' => ['error' => 'DeviceNotRegistered']],
        ]]);

        $result = $this->expo($http)->send(PushMessage::to(self::TOKEN_A))->throwIfRequestFailed();

        self::assertCount(1, $result->notAccepted());
    }

    public function testThrowIfRequestFailedCarriesTheWholeResult(): void
    {
        $http = new FakeHttpClient();
        $http->queue(['data' => self::okTickets(2)]);
        $http->queueRaw('<html>500</html>', 500);

        $result = $this->expo($http, new NoRetryPolicy(), sendChunkSize: 2)
            ->send(PushMessage::to(self::tokens(4))->title('Hi'));

        try {
            $result->throwIfRequestFailed();
            self::fail('The result must raise SendFailedException.');
        } catch (SendFailedException $exception) {
            self::assertSame($result, $exception->result);
            self::assertCount(2, $exception->result->accepted());
            self::assertCount(2, $exception->result->unknown());
            self::assertStringContainsString('accepted=2', $exception->getMessage());
        }
    }

    /**
     * The first 100 notifications succeed, the next chunk exhausts its ambiguous
     * retries, and the last 50 never go out. Every outcome stays available.
     */
    public function testAPartialBatchKeepsEveryOutcomeAndEveryReceiptId(): void
    {
        $http = new FakeHttpClient();
        $http->queue(['data' => self::okTickets(100, 'a')]);
        $http->queueFailure(TransportFailureKind::Timeout);
        $http->queueFailure(TransportFailureKind::Timeout);
        $http->queueFailure(TransportFailureKind::Timeout);

        $result = $this->expo($http)->send(PushMessage::to(self::tokens(250))->title('Hi'));

        self::assertSame(4, $http->requestCount());
        self::assertSame(250, $result->count());
        self::assertCount(100, $result->accepted());
        self::assertCount(100, $result->unknown());
        self::assertCount(50, $result->notAttempted());
        self::assertCount(100, $result->receiptReferences());
        self::assertSame('a0', $result->receiptReferences()[0]->id);
        self::assertSame(self::tokens(1)[0], $result->receiptReferences()[0]->token?->value);

        [$first, $second] = $result->requestFailures();

        self::assertSame([100, 199], $first->indexRange());
        self::assertSame(100, $first->size());
        self::assertSame(FailureCategory::Transport, $first->category);
        self::assertSame(3, $first->attemptCount());
        self::assertTrue($first->retryable);

        self::assertSame([200, 249], $second->indexRange());
        self::assertSame(50, $second->size());
        self::assertSame(FailureCategory::Skipped, $second->category);

        self::assertSame(Acceptance::Unknown, $result->outcome(150)?->acceptance);
        self::assertSame(Acceptance::NotAttempted, $result->outcome(249)?->acceptance);
        self::assertSame(249, $result->outcome(249)->recipientIndex);
        self::assertSame(0, $result->outcome(249)->messageKey);
    }

    public function testContinueAfterFailureActivatesTheLaterChunks(): void
    {
        $http = new FakeHttpClient();
        $http->queue(['data' => self::okTickets(2)]);
        $http->queueRaw('nope', 400);
        $http->queue(['data' => self::okTickets(2, 'z')]);

        $result = $this->expo($http, new NoRetryPolicy(), continueAfterFailure: true, sendChunkSize: 2)
            ->send(PushMessage::to(self::tokens(6))->title('Hi'));

        self::assertSame(3, $http->requestCount());
        self::assertCount(4, $result->accepted());
        // A 400 answer means that the server refused the request and acted on
        // nothing, so those two notifications are known not accepted.
        self::assertCount(2, $result->notAccepted());
        self::assertSame(NotAcceptedReason::Rejected, $result->outcome(2)?->reason);
        self::assertCount(1, $result->requestFailures());
    }

    /**
     * An invalid message in a later chunk must not let the earlier ones go out.
     */
    public function testAnInvalidLaterMessageSendsNothingAtAll(): void
    {
        $http = new FakeHttpClient();
        $messages = [
            PushMessage::to(self::TOKEN_A)->title('One'),
            PushMessage::to(self::TOKEN_B)->title('Two'),
        ];

        /** @var list<mixed> $mixed */
        $mixed = [...$messages, 'not a message'];

        try {
            self::ignoreResult($this->expo($http)->send($mixed));
            self::fail('The send must raise InvalidMessageException.');
        } catch (InvalidMessageException $exception) {
            self::assertStringContainsString('Every item must be a PushMessage', $exception->getMessage());
        }

        self::assertSame(0, $http->requestCount());
    }

    public function testABrokenGeneratorSendsNothingAtAll(): void
    {
        $http = new FakeHttpClient();

        $messages = static function (): Generator {
            yield PushMessage::to(self::TOKEN_A)->title('One');

            throw new \RuntimeException('the database went away');
        };

        try {
            self::ignoreResult($this->expo($http)->send($messages()));
            self::fail('The send must let the generator failure through.');
        } catch (\RuntimeException $exception) {
            self::assertSame('the database went away', $exception->getMessage());
        }

        self::assertSame(0, $http->requestCount());
    }

    public function testAMessageThatCannotEncodeSendsNothingAtAll(): void
    {
        $http = new FakeHttpClient();

        $this->expectException(InvalidMessageException::class);

        try {
            $this->expo($http)->send([
                PushMessage::to(self::TOKEN_A)->title('One'),
                PushMessage::to(self::TOKEN_B)->data(['bad' => "\xB1\x31"]),
            ]);
        } finally {
            self::assertSame(0, $http->requestCount());
        }
    }

    public function testAnOversizedLaterMessageSendsNothingAtAll(): void
    {
        $http = new FakeHttpClient();

        try {
            self::ignoreResult($this->expo($http)->send([
                PushMessage::to(self::TOKEN_A)->title('One'),
                PushMessage::to(self::TOKEN_B)->data(['blob' => str_repeat('x', 5000)]),
            ]));
            self::fail('The send must raise MessageTooLargeException.');
        } catch (MessageTooLargeException $exception) {
            self::assertSame(4096, $exception->limit);
            self::assertGreaterThan(5000, $exception->size);
        }

        self::assertSame(0, $http->requestCount());
    }

    public function testTheSizeCheckCanBeTurnedOff(): void
    {
        $http = (new FakeHttpClient())->queue(['data' => self::okTickets(1)]);

        $result = $this->expo($http, validateSize: false)
            ->send(PushMessage::to(self::TOKEN_A)->data(['blob' => str_repeat('x', 5000)]));

        self::assertCount(1, $result->accepted());
    }

    public function testItSendsTheAccessTokenAndTheStandardHeaders(): void
    {
        $http = (new FakeHttpClient())->queue(['data' => self::okTickets(1)]);
        $expo = new \Expo\Push\Expo(
            accessToken: 'secret',
            httpClient: $http,
            clock: $this->clock,
            sleeper: $this->sleeper,
        );

        $result = $expo->send(PushMessage::to(self::TOKEN_A));

        self::assertCount(1, $result->accepted());

        $headers = $http->headers();
        self::assertSame('Bearer secret', $headers['authorization']);
        self::assertSame('application/json', $headers['content-type']);
        self::assertSame('application/json', $headers['accept']);
        self::assertStringStartsWith('expo-sdk-php/', $headers['user-agent']);
    }

    public function testItCompressesALargeBody(): void
    {
        $http = (new FakeHttpClient())->queue(['data' => self::okTickets(1)]);

        $result = $this->expo($http)->send(PushMessage::to(self::TOKEN_A)->body(str_repeat('a', 2000)));

        self::assertCount(1, $result->accepted());
        self::assertSame('gzip', $http->headers()['content-encoding'] ?? null);
        self::assertCount(1, $http->payload());
    }

    public function testASmallBodyGoesOutWithoutCompression(): void
    {
        $http = (new FakeHttpClient())->queue(['data' => self::okTickets(1)]);

        $result = $this->expo($http)->send(PushMessage::to(self::TOKEN_A)->body('short'));

        self::assertCount(1, $result->accepted());
        self::assertArrayNotHasKey('content-encoding', $http->headers());
    }

    public function testTheResultSummaryHoldsNoToken(): void
    {
        $http = (new FakeHttpClient())->queue(['data' => self::okTickets(1)]);

        $summary = $this->expo($http)->send(PushMessage::to(self::TOKEN_A))->summary();

        self::assertSame(1, $summary['total']);
        self::assertSame(1, $summary['accepted']);
        self::assertStringNotContainsString('ExponentPushToken', json_encode($summary, JSON_THROW_ON_ERROR));
    }
}
