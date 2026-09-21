<?php

declare(strict_types=1);

namespace Expo\Push\Tests\Unit;

use Expo\Push\Exception\InvalidConfigurationException;
use Expo\Push\Expo;
use Expo\Push\Http\Psr18HttpClient;
use Expo\Push\Http\TransportFailureKind;
use Expo\Push\Observability\AttemptFinished;
use Expo\Push\Observability\ChunkDeferred;
use Expo\Push\Observability\ChunkFinished;
use Expo\Push\Observability\ChunkStarted;
use Expo\Push\Observability\OperationFinished;
use Expo\Push\Observability\OperationStarted;
use Expo\Push\Observability\WaitReason;
use Expo\Push\Observability\WaitScheduled;
use Expo\Push\PushMessage;
use Expo\Push\Result\OperationType;
use Expo\Push\Tests\Support\FakeHttpClient;
use Expo\Push\Tests\Support\Psr\FakePsr18Client;
use Expo\Push\Tests\Support\Psr\FakePsrFactory;
use Expo\Push\Tests\Support\RecordingObserver;
use Expo\Push\Tests\Support\TestCase;

final class ObserverTest extends TestCase
{
    public function testTheObserverSeesTheWholeLifecycle(): void
    {
        $http = new FakeHttpClient();
        $http->queueRaw('boom', 500);
        $http->queue(['data' => [['status' => 'ok', 'id' => 'r1']]]);

        $observer = new RecordingObserver();

        $result = $this->expo($http, observer: $observer)->send(PushMessage::to(self::TOKEN_A));

        self::assertCount(1, $result->accepted());
        self::assertSame([
            'OperationStarted',
            'ChunkStarted',
            'AttemptFinished',
            'WaitScheduled',
            'ChunkStarted',
            'AttemptFinished',
            'ChunkFinished',
            'OperationFinished',
        ], $observer->names());

        $started = $observer->ofType(OperationStarted::class)[0];
        self::assertInstanceOf(OperationStarted::class, $started);
        self::assertSame(OperationType::Send, $started->operation);
        self::assertSame(1, $started->items);
        self::assertSame(1, $started->chunks);
        self::assertSame(1, $started->concurrency);

        $wait = $observer->ofType(WaitScheduled::class)[0];
        self::assertInstanceOf(WaitScheduled::class, $wait);
        self::assertSame(WaitReason::Retry, $wait->reason);
        self::assertSame(2, $wait->nextAttempt);

        $attempt = $observer->ofType(AttemptFinished::class)[0];
        self::assertInstanceOf(AttemptFinished::class, $attempt);
        self::assertSame(500, $attempt->record->status);

        $finished = $observer->ofType(ChunkFinished::class)[0];
        self::assertInstanceOf(ChunkFinished::class, $finished);
        self::assertTrue($finished->succeeded);
        self::assertSame(2, $finished->attempts);

        $done = $observer->ofType(OperationFinished::class)[0];
        self::assertInstanceOf(OperationFinished::class, $done);
        self::assertSame(1, $done->summary['accepted']);
        self::assertSame(0, $done->observerFailures);
    }

    public function testEveryOperationHasItsOwnId(): void
    {
        $http = new FakeHttpClient();
        $http->queue(['data' => [['status' => 'ok', 'id' => 'r1']]]);
        $http->queue(['data' => [['status' => 'ok', 'id' => 'r2']]]);

        $observer = new RecordingObserver();
        $expo = $this->expo($http, observer: $observer);

        $first = $expo->send(PushMessage::to(self::TOKEN_A));
        $second = $expo->send(PushMessage::to(self::TOKEN_B));

        self::assertCount(1, $first->accepted());
        self::assertCount(1, $second->accepted());

        $ids = array_map(
            static fn (\Expo\Push\Observability\Event $event): string => $event->operationId,
            $observer->ofType(OperationStarted::class)
        );

        self::assertCount(2, array_unique($ids));
    }

    public function testNoEventFieldHoldsATokenOrABody(): void
    {
        $http = new FakeHttpClient();
        $http->queueRaw('the server said ' . self::TOKEN_A, 500);
        $http->queue(['data' => [['status' => 'ok', 'id' => 'r1']]]);

        $observer = new RecordingObserver();

        $result = $this->expo($http, observer: $observer)->send(
            PushMessage::to(self::TOKEN_A)->title('Secret title')->body('Secret body')->data(['pin' => '1234'])
        );

        self::assertCount(1, $result->accepted());

        $text = implode(' | ', $observer->textFields());

        self::assertStringNotContainsString('ExponentPushToken', $text);
        self::assertStringNotContainsString('Secret title', $text);
        self::assertStringNotContainsString('Secret body', $text);
        self::assertStringNotContainsString('1234', $text);
    }

    public function testADeferralReachesTheObserver(): void
    {
        $http = (new FakeHttpClient())->queueRaw('slow', 429, ['Retry-After' => '300']);
        $observer = new RecordingObserver();

        $result = $this->expo($http, observer: $observer)->send(PushMessage::to(self::TOKEN_A));

        self::assertCount(1, $result->requestFailures());

        $deferred = $observer->ofType(ChunkDeferred::class)[0];

        self::assertInstanceOf(ChunkDeferred::class, $deferred);
        self::assertSame($this->clock->nowUtcMillis() + 300_000, $deferred->earliestRetryAtUtcMs);
        self::assertSame(1, $deferred->size);
    }

    /**
     * A broken observer must never erase a result and never stop an operation.
     */
    public function testABrokenObserverCannotEraseASuccess(): void
    {
        $http = (new FakeHttpClient())->queue(['data' => [['status' => 'ok', 'id' => 'r1']]]);
        $observer = new RecordingObserver(failOn: ChunkStarted::class);

        $result = $this->expo($http, observer: $observer)->send(PushMessage::to(self::TOKEN_A));

        self::assertCount(1, $result->accepted());
        self::assertSame('r1', $result->outcomes()[0]->receiptId());
    }

    public function testABrokenObserverStopsAfterTheFailureCap(): void
    {
        $http = new FakeHttpClient();

        for ($index = 0; $index < 12; ++$index) {
            $http->queue(['data' => [['status' => 'ok', 'id' => 'r' . $index]]]);
        }

        $observer = new RecordingObserver(failOn: ChunkStarted::class);

        $result = $this->expo($http, observer: $observer, sendChunkSize: 1)
            ->send(PushMessage::to(self::tokens(12))->title('Hi'));

        self::assertCount(12, $result->accepted());
        // The SDK stopped calling the observer after five failures.
        self::assertLessThanOrEqual(5, count($observer->ofType(ChunkStarted::class)));
    }

    public function testTheOperationFinishedEventCountsTheObserverFailures(): void
    {
        $http = (new FakeHttpClient())->queue(['data' => [['status' => 'ok', 'id' => 'r1']]]);
        $observer = new RecordingObserver(failOn: ChunkStarted::class);

        $result = $this->expo($http, observer: $observer)->send(PushMessage::to(self::TOKEN_A));

        self::assertCount(1, $result->accepted());

        $done = $observer->ofType(OperationFinished::class)[0];

        self::assertInstanceOf(OperationFinished::class, $done);
        self::assertSame(1, $done->observerFailures);
    }

    public function testAHardDeadlineNeedsATransportThatCanEnforceIt(): void
    {
        $factory = new FakePsrFactory();
        $psr18 = new Psr18HttpClient(new FakePsr18Client(), $factory, $factory);

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('cannot stop a request that is already running');

        new Expo(httpClient: $psr18, operationDeadlineMs: 1_000, enforceHardDeadline: true);
    }

    public function testAHardDeadlineNeedsADeadlineValue(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('needs an operationDeadlineMs');

        new Expo(enforceHardDeadline: true);
    }

    public function testACurlTransportAcceptsAHardDeadline(): void
    {
        $expo = new Expo(operationDeadlineMs: 5_000, enforceHardDeadline: true);

        self::assertInstanceOf(Expo::class, $expo);
    }

    /**
     * A chunk that may already be on the wire never becomes "not attempted".
     */
    public function testADeadlineNeverTurnsATransmittedChunkIntoNotAttempted(): void
    {
        $http = (new FakeHttpClient())->queueFailure(TransportFailureKind::Timeout);

        $result = $this->expo(
            $http,
            new \Expo\Push\Retry\NoRetryPolicy(),
            operationDeadlineMs: 1,
            sendChunkSize: 1,
        )->send(PushMessage::to(self::TOKEN_A));

        self::assertCount(0, $result->notAttempted());
        self::assertCount(1, $result->unknown());
    }
}
