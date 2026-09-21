<?php

declare(strict_types=1);

namespace Expo\Push\Tests\Unit;

use Expo\Push\Exception\InvalidConfigurationException;
use Expo\Push\Expo;
use Expo\Push\Http\TransportFailureKind;
use Expo\Push\PushMessage;
use Expo\Push\Result\Acceptance;
use Expo\Push\Result\FailureCategory;
use Expo\Push\Retry\NoRetryPolicy;
use Expo\Push\Tests\Support\FakeConcurrentHttpClient;
use Expo\Push\Tests\Support\FakeHttpClient;
use Expo\Push\Tests\Support\TestCase;

final class ConcurrencyTest extends TestCase
{
    public function testAnAnswerThatArrivesLastStillKeepsItsInputPosition(): void
    {
        $http = new FakeConcurrentHttpClient();
        // Chunk 0 needs three polls, chunk 1 needs one.
        $http->queue(['data' => [['status' => 'ok', 'id' => 'first']]], 200, 3);
        $http->queue(['data' => [['status' => 'ok', 'id' => 'second']]], 200, 1);

        $result = $this->expo($http, concurrency: 2, sendChunkSize: 1)
            ->send(PushMessage::to([self::TOKEN_A, self::TOKEN_B]));

        self::assertSame([1, 0], $http->finishOrder);
        self::assertSame('first', $result->outcomes()[0]->receiptId());
        self::assertSame('second', $result->outcomes()[1]->receiptId());
        self::assertSame(self::TOKEN_A, $result->outcomes()[0]->token->value);
    }

    public function testTheSchedulerNeverHoldsMoreThanTheConcurrency(): void
    {
        $http = new FakeConcurrentHttpClient();

        for ($index = 0; $index < 8; ++$index) {
            $http->queue(['data' => [['status' => 'ok', 'id' => 'r' . $index]]], 200, 2);
        }

        $result = $this->expo($http, concurrency: 3, sendChunkSize: 1)
            ->send(PushMessage::to(self::tokens(8))->title('Hi'));

        self::assertSame(3, $http->maxObservedInFlight());
        self::assertCount(8, $result->accepted());
        self::assertSame(8, $http->requestCount());
    }

    public function testAFailedChunkStopsTheLaterOnesAndKeepsTheActiveSuccesses(): void
    {
        $http = new FakeConcurrentHttpClient();
        $http->queueRaw('boom', 400, 1);
        $http->queue(['data' => [['status' => 'ok', 'id' => 'ok-1']]], 200, 2);

        $result = $this->expo($http, new NoRetryPolicy(), concurrency: 2, sendChunkSize: 1)
            ->send(PushMessage::to(self::tokens(4))->title('Hi'));

        // Two chunks started together. The first failed, and the second still
        // finished its own work. The last two chunks never started.
        self::assertSame(2, $http->requestCount());
        self::assertSame(Acceptance::NotAccepted, $result->outcomes()[0]->acceptance);
        self::assertSame(Acceptance::Accepted, $result->outcomes()[1]->acceptance);
        self::assertSame('ok-1', $result->outcomes()[1]->receiptId());
        self::assertSame(Acceptance::NotAttempted, $result->outcomes()[2]->acceptance);
        self::assertSame(Acceptance::NotAttempted, $result->outcomes()[3]->acceptance);
        self::assertSame(FailureCategory::Skipped, $result->requestFailures()[1]->category);
    }

    public function testContinueAfterFailureStartsTheLaterChunks(): void
    {
        $http = new FakeConcurrentHttpClient();
        $http->queueRaw('boom', 400, 1);
        $http->queue(['data' => [['status' => 'ok', 'id' => 'ok-1']]], 200, 1);
        $http->queue(['data' => [['status' => 'ok', 'id' => 'ok-2']]], 200, 1);
        $http->queue(['data' => [['status' => 'ok', 'id' => 'ok-3']]], 200, 1);

        $result = $this->expo(
            $http,
            new NoRetryPolicy(),
            concurrency: 2,
            continueAfterFailure: true,
            sendChunkSize: 1,
        )->send(PushMessage::to(self::tokens(4))->title('Hi'));

        self::assertSame(4, $http->requestCount());
        self::assertCount(3, $result->accepted());
        self::assertCount(1, $result->notAccepted());
    }

    /**
     * A chunk that waits for its backoff must not block the other chunks.
     */
    public function testARetryWaitDoesNotBlockTheOtherChunks(): void
    {
        $http = new FakeConcurrentHttpClient();
        $http->queueRaw('boom', 500, 1);                                    // chunk 0, attempt 1
        $http->queue(['data' => [['status' => 'ok', 'id' => 'b']]], 200, 4); // chunk 1
        $http->queue(['data' => [['status' => 'ok', 'id' => 'a']]], 200, 1); // chunk 0, attempt 2

        $result = $this->expo($http, concurrency: 2, sendChunkSize: 1, continueAfterFailure: true)
            ->send(PushMessage::to([self::TOKEN_A, self::TOKEN_B]));

        self::assertCount(2, $result->accepted());
        self::assertSame('a', $result->outcomes()[0]->receiptId());
        self::assertSame('b', $result->outcomes()[1]->receiptId());
        // Chunk 1 started and finished while chunk 0 waited for its backoff. The
        // SDK polled the transport instead of blocking on the wait.
        self::assertSame([0, 1, 0], $http->startOrder);
        self::assertSame([0, 1, 0], $http->finishOrder);
        // One backoff, and the SDK waited it only after every other chunk ended.
        self::assertSame([1000], $this->sleeper->waits);
    }

    public function testASequentialTransportRejectsAConcurrencyAboveOne(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('runs at most 1 request');

        new Expo(httpClient: new FakeHttpClient(), concurrency: 2);
    }

    public function testAConcurrencyAboveSixIsRejected(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('between 1 and 6');

        new Expo(httpClient: new FakeConcurrentHttpClient(), concurrency: 7);
    }

    public function testAConcurrencyBelowOneIsRejected(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        new Expo(httpClient: new FakeConcurrentHttpClient(), concurrency: 0);
    }

    public function testTheRejectionHappensBeforeAnyRequest(): void
    {
        $http = new FakeHttpClient();

        try {
            new Expo(httpClient: $http, concurrency: 4);
        } catch (InvalidConfigurationException) {
            // expected
        }

        self::assertSame(0, $http->requestCount());
    }

    public function testConcurrencyOneUsesTheSequentialPathOfTheSameTransport(): void
    {
        $http = new FakeConcurrentHttpClient();
        $http->queue(['data' => [['status' => 'ok', 'id' => 'r0']]]);
        $http->queue(['data' => [['status' => 'ok', 'id' => 'r1']]]);

        $result = $this->expo($http, concurrency: 1, sendChunkSize: 1)
            ->send(PushMessage::to([self::TOKEN_A, self::TOKEN_B]));

        self::assertCount(2, $result->accepted());
        self::assertSame(2, $http->requestCount());
        // A concurrency of 1 uses the plain send path, not the multi path.
        self::assertSame([], $http->startOrder);
    }

    public function testAnAmbiguousChunkAmongSuccessfulOnesKeepsItsOwnState(): void
    {
        $http = new FakeConcurrentHttpClient();
        $http->queue(['data' => [['status' => 'ok', 'id' => 'a']]], 200, 1);
        $http->queueFailure(TransportFailureKind::Timeout, 'timeout', 1);

        $result = $this->expo($http, new NoRetryPolicy(), concurrency: 2, sendChunkSize: 1)
            ->send(PushMessage::to([self::TOKEN_A, self::TOKEN_B]));

        self::assertSame(Acceptance::Accepted, $result->outcomes()[0]->acceptance);
        self::assertSame(Acceptance::Unknown, $result->outcomes()[1]->acceptance);
        self::assertTrue($result->outcomes()[1]->duplicateRisk);
        self::assertFalse($result->outcomes()[0]->duplicateRisk);
    }
}
