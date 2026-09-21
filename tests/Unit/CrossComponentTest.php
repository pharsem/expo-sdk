<?php

declare(strict_types=1);

namespace Expo\Push\Tests\Unit;

use Expo\Push\Exception\InvalidMessageException;
use Expo\Push\Exception\InvalidStorageException;
use Expo\Push\Expo;
use Expo\Push\Observability\ChunkStarted;
use Expo\Push\PushMessage;
use Expo\Push\PushReceipt;
use Expo\Push\Result\Acceptance;
use Expo\Push\Result\FailureCategory;
use Expo\Push\Result\NotificationOutcome;
use Expo\Push\Result\ReceiptEntry;
use Expo\Push\Result\ReceiptResult;
use Expo\Push\Result\ReceiptState;
use Expo\Push\Result\RecoverableWork;
use Expo\Push\Result\RecoveryDisposition;
use Expo\Push\Result\SendResult;
use Expo\Push\Support\FixedJitter;
use Expo\Push\Support\Json;
use Expo\Push\Support\JsonObject;
use Expo\Push\Tests\Support\FakeConcurrentHttpClient;
use Expo\Push\Tests\Support\FakeHttpClient;
use Expo\Push\Tests\Support\RecordingObserver;
use Expo\Push\Tests\Support\TestCase;
use Generator;
use stdClass;

/**
 * Recovery, retries, scheduling, persistence and merging tell one story.
 *
 * Each test here crosses at least two of them, because the parts agreed on
 * their own in the review and disagreed as soon as they met.
 */
final class CrossComponentTest extends TestCase
{
    /**
     * Every unresolved notification lands in a category, and nothing accepted
     * becomes automatic resend work.
     */
    public function testEveryUnresolvedNotificationLandsInACategory(): void
    {
        $http = new FakeHttpClient();
        $http->queue(['data' => [['status' => 'ok', 'id' => 'ticket-0']]]);
        $http->queue(['data' => [
            ['status' => 'error', 'message' => 'gone', 'details' => ['error' => 'DeviceNotRegistered']],
        ]]);
        $http->queueRaw('slow down', 429, ['Retry-After' => '60']);

        $result = $this->expo($http, sendChunkSize: 1)->send(PushMessage::to(self::tokens(4))->title('Hi'));
        $work = $result->recoverable();

        foreach ($result->outcomes() as $outcome) {
            if ($outcome->isAccepted()) {
                self::assertSame(RecoveryDisposition::None, $outcome->recovery, 'index ' . $outcome->index);

                continue;
            }

            if ($outcome->acceptance === Acceptance::NotAccepted && $outcome->ticket?->isError() === true) {
                // A dead token is closed work.
                self::assertSame(RecoveryDisposition::None, $outcome->recovery);

                continue;
            }

            self::assertTrue($outcome->isOpen(), 'index ' . $outcome->index . ' must stay open work');
            self::assertContains($outcome, $work->outcomes, 'index ' . $outcome->index . ' must be in the worklist');
        }

        self::assertSame(
            count($work->retryable()) + count($work->needsIntervention()),
            $work->count(),
            'every open notification has exactly one disposition'
        );
    }

    /**
     * A concurrent partial send with a deferred 429, then storage, then the
     * receipt lookups of what did get through.
     */
    public function testAConcurrentPartialSendStoresAndRestoresItsWholeStory(): void
    {
        $http = new FakeConcurrentHttpClient();
        // Chunk 0 succeeds at once.
        $http->queue(['data' => [['status' => 'ok', 'id' => 'receipt-0']]]);
        // Chunk 1 hits a 429 that outlives the inline allowance.
        $http->queue([], 429, 1, ['Retry-After' => '600']);

        $observer = new RecordingObserver();
        $expo = new Expo(
            httpClient: $http,
            concurrency: 2,
            observer: $observer,
            continueAfterFailure: true,
            sendChunkSize: 1,
            clock: $this->clock,
            sleeper: $this->sleeper,
            jitter: new FixedJitter(1.0),
        );

        $sentAt = $this->clock->nowUtcMillis();
        $result = $expo->send(PushMessage::to(self::tokens(4))->title('Hi')->reference('release'));

        // Two chunks went out, and the cooldown stopped the other two.
        self::assertSame(2, $http->requestCount());
        self::assertSame([0, 1], array_map(
            static fn (ChunkStarted $event): int => $event->chunk,
            array_values(array_filter(
                $observer->events,
                static fn (object $e): bool => $e instanceof ChunkStarted
            ))
        ));
        self::assertSame([], $this->sleeper->waits, 'a ten minute cooldown never sleeps inside the call');

        self::assertSame(Acceptance::Accepted, $result->outcome(0)?->acceptance);
        self::assertSame(Acceptance::NotAccepted, $result->outcome(1)?->acceptance);
        self::assertSame(Acceptance::NotAttempted, $result->outcome(2)?->acceptance);
        self::assertSame(Acceptance::NotAttempted, $result->outcome(3)?->acceptance);

        $due = $sentAt + 600_000;

        foreach ([1, 2, 3] as $index) {
            $open = $result->outcome($index);

            self::assertNotNull($open, 'index ' . $index);
            self::assertSame($due, $open->earliestRetryAtUtcMs, 'index ' . $index);
            self::assertSame(RecoveryDisposition::Retryable, $open->recovery, 'index ' . $index);
        }

        // Store the whole result, and read it back in another process.
        $json = json_encode($result->toStorageArray(), JSON_THROW_ON_ERROR);
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        self::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        $restored = SendResult::fromStorageArray($decoded);
        $work = $restored->recoverable();

        self::assertSame([1, 2, 3], $work->indexes());
        self::assertSame($due, $work->earliestRetryAtUtcMs);
        self::assertSame([], $work->dueAt($due - 1));
        self::assertCount(3, $work->dueAt($due));
        self::assertSame(['release'], $work->references());
        self::assertSame(3, count($work->tokens()));
        self::assertSame([], $work->ambiguous(), 'a 429 refused the request, so nothing can duplicate');

        // The one accepted notification keeps its receipt reference.
        self::assertSame(['receipt-0'], array_map(
            static fn (\Expo\Push\Result\ReceiptReference $r): string => $r->id,
            $restored->receiptReferences()
        ));
        self::assertSame(self::tokens(4)[0], $restored->receiptReferences()[0]->token?->value);
    }

    /**
     * The receipt lookups of that send, repeated and merged.
     */
    public function testRepeatedReceiptLookupsMergeIntoOneHonestAnswer(): void
    {
        $references = $this->acceptedReferences();

        $firstHttp = (new FakeHttpClient())->queue(['data' => [
            'receipt-0' => ['status' => 'ok'],
        ]]);
        $first = $this->expo($firstHttp)->receipts($references);

        self::assertSame(['receipt-0'], $first->returnedIds());
        self::assertSame(['receipt-1'], $first->missingIds());

        // The second lookup finds the missing one, and contradicts the first.
        $secondHttp = (new FakeHttpClient())->queue(['data' => [
            'receipt-0' => ['status' => 'error', 'message' => 'gone', 'details' => ['error' => 'DeviceNotRegistered']],
            'receipt-1' => ['status' => 'ok'],
        ]]);
        $second = $this->expo($secondHttp)->receipts($references);

        $merged = $first->merge($second);

        self::assertSame(['receipt-0', 'receipt-1'], $merged->returnedIds());
        self::assertSame(['receipt-0'], $merged->conflicts());
        self::assertSame('ok', $merged->get('receipt-0')?->status, 'the first answer stands');
        self::assertTrue($merged->isComplete());

        // A third, empty merge still knows about the contradiction, and so does
        // a storage round trip of it.
        $again = (new ReceiptResult())->merge($merged)->merge(new ReceiptResult());

        self::assertSame(['receipt-0'], $again->conflicts());
        self::assertSame(
            ['receipt-0'],
            ReceiptResult::fromStorageArray($again->toStorageArray())->conflicts()
        );

        // The token association of the send survives every step.
        $entry = $again->entry('receipt-0');

        self::assertNotNull($entry);
        self::assertSame(self::tokens(2)[0], $entry->token?->value);
        self::assertSame(0, $entry->notificationIndex);
    }

    /**
     * Invalid input fails before any request of the whole operation.
     */
    public function testInvalidInputStopsTheWholeOperationBeforeTheNetwork(): void
    {
        $http = (new FakeHttpClient())->queue(['data' => self::okTickets(1)]);

        $messages = static function (): Generator {
            yield PushMessage::to(self::TOKEN_A)->title('fine');

            $loop = new stdClass();
            $loop->self = $loop;

            yield PushMessage::to(self::TOKEN_B)->title('broken')->data($loop);
        };

        $this->expectException(InvalidMessageException::class);

        try {
            self::ignoreResult($this->expo($http)->send($messages()));
        } finally {
            self::assertSame(0, $http->requestCount());
        }
    }

    /**
     * Storage cannot invent a stronger certainty than its evidence supports.
     */
    public function testStorageCannotRaiseCertaintyOrLowerRisk(): void
    {
        $http = new FakeHttpClient();
        $http->queueFailure(\Expo\Push\Http\TransportFailureKind::Timeout);
        $http->queue(['data' => [
            ['status' => 'error', 'message' => 'gone', 'details' => ['error' => 'DeviceNotRegistered']],
        ]]);

        $result = $this->expo($http)->send(PushMessage::to(self::TOKEN_A)->title('Hi'));
        $stored = $result->outcome(0)?->toStorageArray();

        self::assertIsArray($stored);
        self::assertIsArray($stored['data']);

        /** @var array<string, mixed> $data */
        $data = $stored['data'];

        // An unknown outcome cannot be relabelled as accepted: the ticket
        // behind it reports an error.
        $stronger = $data;
        $stronger['acceptance'] = 'accepted';
        $stored['data'] = $stronger;

        try {
            NotificationOutcome::fromStorageArray($stored);
            self::fail('Storage promoted an unknown outcome to accepted.');
        } catch (InvalidStorageException $exception) {
            self::assertStringContainsString('successful ticket', $exception->getMessage());
        }

        // And the duplicate risk cannot go away by deleting it.
        $safer = $data;
        unset($safer['duplicateRisk']);
        $stored['data'] = $safer;

        $this->expectException(InvalidStorageException::class);

        NotificationOutcome::fromStorageArray($stored);
    }

    /**
     * A deadline bounds the wait, keeps the evidence, and never breaks a
     * server delay.
     */
    public function testADeadlineBoundsTheWaitWithoutLosingAnything(): void
    {
        $http = new FakeConcurrentHttpClient();
        $http->queue(['data' => [['status' => 'ok', 'id' => 'receipt-0']]]);
        // Five seconds fits the inline allowance, so the chunk waits and the
        // deadline is what ends the wait.
        $http->queue([], 429, 1, ['Retry-After' => '5']);
        $http->queue(['data' => [['status' => 'ok', 'id' => 'receipt-1']]]);

        $expo = new Expo(
            httpClient: $http,
            concurrency: 2,
            continueAfterFailure: true,
            operationDeadlineMs: 200,
            sendChunkSize: 1,
            clock: $this->clock,
            sleeper: $this->sleeper,
            jitter: new FixedJitter(1.0),
        );

        $startedAt = $this->clock->monotonicMillis();
        $sentAt = $this->clock->nowUtcMillis();
        $result = $expo->send(PushMessage::to(self::tokens(3))->title('Hi'));

        self::assertSame(200, $this->clock->monotonicMillis() - $startedAt);
        self::assertSame(2, $http->requestCount());

        // The success stays a success.
        self::assertSame('receipt-0', $result->outcome(0)?->receiptId());
        self::assertFalse($result->isCompleteSuccess());

        // The refused chunk keeps its 429, and the delay of the server, not the
        // deadline, names the moment.
        $refused = $result->outcome(1);
        $untouched = $result->outcome(2);

        self::assertNotNull($refused);
        self::assertNotNull($untouched);
        self::assertSame(Acceptance::NotAccepted, $refused->acceptance);
        self::assertSame($sentAt + 5_000, $refused->earliestRetryAtUtcMs);

        // The chunk that never started says exactly that.
        self::assertSame(Acceptance::NotAttempted, $untouched->acceptance);
        self::assertFalse($untouched->duplicateRisk);
        self::assertSame(FailureCategory::Deadline, $result->requestFailures()[1]->category);
    }

    /**
     * Nothing outside a message changes what a send puts on the wire.
     */
    public function testExternalMutationNeverChangesWhatGoesOnTheWire(): void
    {
        $input = (object) ['orderId' => 1, 'nested' => (object) ['deep' => 'a']];
        $message = PushMessage::to(self::TOKEN_A)->title('Hi')->data($input);

        $http = (new FakeHttpClient())->queue(['data' => self::okTickets(1)]);

        $input->orderId = 2;
        $input->nested->deep = 'b';

        $data = $message->data;

        self::assertInstanceOf(JsonObject::class, $data);

        try {
            $data->__set('orderId', 3);
        } catch (\LogicException) {
            // Expected: the message rejects the write.
        }

        self::ignoreResult($this->expo($http)->send($message));

        // The body holds one entry for each message slice.
        $payload = $http->payload();

        self::assertIsArray($payload[0]);
        self::assertSame(['orderId' => 1, 'nested' => ['deep' => 'a']], $payload[0]['data']);
    }

    /**
     * The recovery worklist of a partial send survives a queue round trip, and
     * a worker can act on it with nothing but the stored bytes.
     */
    public function testAWorkerCanActOnTheStoredWorklistAlone(): void
    {
        $http = (new FakeHttpClient())->queueRaw('slow down', 429, ['Retry-After' => '45']);

        $result = $this->expo($http, sendChunkSize: 2)
            ->send(PushMessage::to(self::tokens(4))->title('Hi')->reference('job-1'));

        $json = Json::encode($result->recoverable()->toStorageArray());
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        self::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        $work = RecoverableWork::fromStorageArray($decoded);
        $due = $this->clock->nowUtcMillis() + 45_000;

        self::assertSame(4, $work->count());
        self::assertSame([0, 1, 2, 3], $work->indexes());
        self::assertSame($due, $work->earliestRetryAtUtcMs);
        self::assertCount(4, $work->retryable());
        self::assertSame([], $work->dueAt($due - 1_000));
        self::assertCount(4, $work->dueAt($due));
        self::assertSame(self::tokens(4), self::values($work->tokens()));
        self::assertSame(['job-1'], $work->references());

        foreach ($work->outcomes as $outcome) {
            self::assertSame($due, $outcome->earliestRetryAtUtcMs);
            self::assertFalse($outcome->duplicateRisk);
            self::assertTrue($outcome->isDueAt($due));
        }
    }

    /**
     * Two accepted notifications, for the receipt tests above.
     *
     * @return list<\Expo\Push\Result\ReceiptReference>
     */
    private function acceptedReferences(): array
    {
        $http = (new FakeHttpClient())->queue(['data' => [
            ['status' => 'ok', 'id' => 'receipt-0'],
            ['status' => 'ok', 'id' => 'receipt-1'],
        ]]);

        return $this->expo($http)
            ->send(PushMessage::to(self::tokens(2))->title('Hi'))
            ->receiptReferences();
    }

    /**
     * A receipt that the SDK knows nothing about never joins a device.
     */
    public function testAnUnexpectedReceiptNeverBorrowsADevice(): void
    {
        $http = (new FakeHttpClient())->queue(['data' => [
            'receipt-0' => ['status' => 'ok'],
            'receipt-99' => ['status' => 'ok'],
        ]]);

        $result = $this->expo($http)->receipts($this->acceptedReferences());

        self::assertSame(['receipt-99'], $result->unexpectedIds());
        self::assertNull($result->entry('receipt-99'));

        $merged = $result->merge(new ReceiptResult([
            new ReceiptEntry('receipt-1', ReceiptState::Returned, new PushReceipt('receipt-1', 'ok')),
        ]));

        self::assertSame(['receipt-99'], $merged->unexpectedIds());
        self::assertSame(['receipt-0', 'receipt-1'], $merged->returnedIds());
    }
}
