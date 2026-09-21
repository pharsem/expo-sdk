<?php

declare(strict_types=1);

namespace Expo\Push\Tests\Unit;

use Expo\Push\Exception\InvalidMessageException;
use Expo\Push\Exception\InvalidStorageException;
use Expo\Push\PushMessage;
use Expo\Push\PushReceipt;
use Expo\Push\PushTicket;
use Expo\Push\PushToken;
use Expo\Push\Result\Acceptance;
use Expo\Push\Result\FailureCategory;
use Expo\Push\Result\NotificationOutcome;
use Expo\Push\Result\OperationType;
use Expo\Push\Result\ReceiptEntry;
use Expo\Push\Result\ReceiptReference;
use Expo\Push\Result\ReceiptResult;
use Expo\Push\Result\ReceiptState;
use Expo\Push\Result\RequestFailure;
use Expo\Push\Result\SendResult;
use Expo\Push\Retry\DeliveryRetryPolicy;
use Expo\Push\Retry\RetrySettings;
use Expo\Push\Support\Json;
use Expo\Push\Support\JsonObject;
use Expo\Push\Tests\Support\FakeHttpClient;
use Expo\Push\Tests\Support\SlowLimiter;
use Expo\Push\Tests\Support\TestCase;
use Generator;
use PHPUnit\Framework\Attributes\DataProvider;
use stdClass;

/**
 * The third round of review findings, and the rules that close them.
 */
final class ReviewFollowUpThirdTest extends TestCase
{
    /**
     * A comparison never runs away, whatever a caller put in the details.
     *
     * The details of a receipt are public, so an application can build one
     * that loops. A merge then compares it, and a comparison may not raise.
     */
    public function testAComparisonOfRecursiveDetailsEndsWithoutACrash(): void
    {
        $loop = [];
        $loop['self'] = &$loop;

        /** @var array<string, mixed> $loop */
        $first = new ReceiptResult([new ReceiptEntry(
            'r-1',
            ReceiptState::Returned,
            new PushReceipt('r-1', 'error', null, 'boom', null, 'X', ['d' => $loop]),
        )]);
        $second = new ReceiptResult([new ReceiptEntry(
            'r-1',
            ReceiptState::Returned,
            new PushReceipt('r-1', 'error', null, 'boom', null, 'X', ['d' => $loop]),
        )]);

        // A value that the comparison cannot walk to the end counts as
        // different, so the contradiction lands in the conflicts.
        self::assertSame(['r-1'], $first->merge($second)->conflicts());
        self::assertFalse(Json::sameJson($loop, $loop));
    }

    public function testAComparisonOfDeepButValidDetailsStillWorks(): void
    {
        $deep = ['leaf' => 1];

        for ($index = 0; $index < 100; ++$index) {
            $deep = ['n' => $deep];
        }

        self::assertTrue(Json::sameJson($deep, $deep));
        self::assertFalse(Json::sameJson($deep, ['n' => 1]));
    }

    /**
     * The deadline speaks before an earlier failure does.
     */
    public function testEveryChunkThatTheDeadlineCatchesReportsTheDeadline(): void
    {
        $http = new FakeHttpClient();
        $limiter = new SlowLimiter($this->clock, 500, denyForMs: 5_000);

        $result = $this->expo($http, rateLimiter: $limiter, bucket: 'p', operationDeadlineMs: 100, sendChunkSize: 1)
            ->send(PushMessage::to(self::tokens(3))->title('Hi'));

        self::assertSame(0, $http->requestCount());
        self::assertCount(3, $result->requestFailures());

        foreach ($result->requestFailures() as $failure) {
            self::assertSame(FailureCategory::Deadline, $failure->category, 'chunk ' . $failure->chunkOrdinal);
            self::assertSame(
                $this->clock->nowUtcMillis() + 5_000,
                $failure->earliestRetryAtUtcMs,
                'chunk ' . $failure->chunkOrdinal
            );
        }

        self::assertCount(3, $result->notAttempted());
    }

    /**
     * A limiter that spends the chunk budget also reports the deadline.
     */
    public function testALimiterThatSpendsTheChunkBudgetReportsTheDeadline(): void
    {
        $settings = new RetrySettings(chunkBudgetMs: 200);
        $http = new FakeHttpClient();
        $limiter = new SlowLimiter($this->clock, 900, denyForMs: 5_000);

        $result = $this->expo($http, new DeliveryRetryPolicy($settings), rateLimiter: $limiter, bucket: 'p')
            ->send(PushMessage::to(self::TOKEN_A)->title('Hi'));

        $failure = $result->requestFailures()[0];

        self::assertSame(0, $http->requestCount());
        self::assertSame(FailureCategory::Deadline, $failure->category);
        self::assertSame($this->clock->nowUtcMillis() + 5_000, $failure->earliestRetryAtUtcMs);
    }

    /**
     * An empty data object comes back as an object, not as a list.
     */
    public function testAnEmptyDataObjectComesBackAsAnObject(): void
    {
        $message = PushMessage::to(self::TOKEN_A)->data(new stdClass());

        $json = json_encode($message->toStorageArray(), JSON_THROW_ON_ERROR);

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $restored = PushMessage::fromStorageArray($decoded);

        self::assertInstanceOf(JsonObject::class, $restored->data);
        self::assertTrue($restored->data->isEmpty());
        self::assertStringContainsString('"data":{}', Json::encode($restored->toExpoArray()));

        // And a numeric key still works on the restored message, exactly as it
        // does on the original one.
        self::assertSame('{"0":"x"}', Json::encode($restored->withDatum('0', 'x')->data));
        self::assertSame('{"0":"x"}', Json::encode($message->withDatum('0', 'x')->data));
    }

    public function testAnEmptyDataArrayAlsoStaysAnObjectOnTheWire(): void
    {
        $message = PushMessage::to(self::TOKEN_A)->data([]);

        $restored = PushMessage::fromStorageArray($message->toStorageArray());

        self::assertStringContainsString('"data":{}', Json::encode($restored->toExpoArray()));
    }

    /**
     * A ticket ID of the wrong type is broken evidence.
     */
    public function testAnErrorTicketWithANumericIdIsRejected(): void
    {
        $stored = [
            '_v' => 1,
            '_type' => PushTicket::STORAGE_TYPE,
            'data' => ['status' => 'error', 'id' => 7, 'errorCode' => 'MessageTooBig'],
        ];

        $this->expectException(InvalidStorageException::class);

        PushTicket::fromStorageArray($stored);
    }

    public function testAnErrorTicketWithoutAnIdStillReads(): void
    {
        $stored = [
            '_v' => 1,
            '_type' => PushTicket::STORAGE_TYPE,
            'data' => ['status' => 'error', 'errorCode' => 'MessageTooBig'],
        ];

        self::assertNull(PushTicket::fromStorageArray($stored)->id);
    }

    /**
     * An outcome token of the wrong shape raises a storage error.
     */
    public function testAnOutcomeTokenOfTheWrongShapeRaisesAStorageError(): void
    {
        $stored = [
            '_v' => 1,
            '_type' => NotificationOutcome::STORAGE_TYPE,
            'data' => [
                'index' => 0,
                'messageKey' => 0,
                'recipientIndex' => 0,
                'token' => 'not-a-token',
                'acceptance' => 'not_attempted',
                'duplicateRisk' => false,
                'recovery' => 'retryable',
            ],
        ];

        $this->expectException(InvalidStorageException::class);

        NotificationOutcome::fromStorageArray($stored);
    }

    /**
     * An error ticket proves that Expo answered, so the reason is a rejection.
     */
    public function testATicketRejectionWithTheWrongReasonIsRejected(): void
    {
        $stored = [
            '_v' => 1,
            '_type' => NotificationOutcome::STORAGE_TYPE,
            'data' => [
                'index' => 0,
                'messageKey' => 0,
                'recipientIndex' => 0,
                'token' => self::TOKEN_A,
                'acceptance' => 'not_accepted',
                'reason' => 'not_transmitted',
                'duplicateRisk' => false,
                'recovery' => 'none',
                'ticket' => [
                    '_v' => 1,
                    '_type' => PushTicket::STORAGE_TYPE,
                    'data' => ['status' => 'error', 'errorCode' => 'MessageTooBig'],
                ],
            ],
        ];

        $this->expectException(InvalidStorageException::class);
        $this->expectExceptionMessage('Expo answered it');

        NotificationOutcome::fromStorageArray($stored);
    }

    /**
     * A merge of two devices records the conflict and keeps one device.
     *
     * The old merge kept the losing token as a later reference, and the entry
     * then named two devices. Its own storage reader refused it.
     */
    public function testAMergeOfTwoDevicesStillReadsItsOwnStorage(): void
    {
        $first = new ReceiptResult([new ReceiptEntry(
            'r-1',
            ReceiptState::Returned,
            new PushReceipt('r-1', 'ok', new PushToken(self::TOKEN_A)),
            new PushToken(self::TOKEN_A),
            1,
        )]);
        $second = new ReceiptResult([new ReceiptEntry(
            'r-1',
            ReceiptState::Returned,
            new PushReceipt('r-1', 'ok', new PushToken(self::TOKEN_B)),
            new PushToken(self::TOKEN_B),
            2,
        )]);

        $merged = $first->merge($second);

        $entry = $merged->entry('r-1');

        self::assertNotNull($entry);
        self::assertSame(['r-1'], $merged->conflicts(), 'the contradiction is on the record');
        self::assertSame(self::TOKEN_A, $entry->token?->value);
        self::assertSame([1], $entry->notificationIndexes());

        $restored = ReceiptResult::fromStorageArray($merged->toStorageArray());

        self::assertSame(['r-1'], $restored->conflicts());
        self::assertSame(self::TOKEN_A, $restored->entry('r-1')?->token?->value);
    }

    /**
     * A reference of the same device still joins.
     */
    public function testAMergeKeepsAReferenceOfTheSameDevice(): void
    {
        $first = new ReceiptResult([new ReceiptEntry(
            'r-1',
            ReceiptState::Returned,
            new PushReceipt('r-1', 'ok', new PushToken(self::TOKEN_A)),
            new PushToken(self::TOKEN_A),
            1,
        )]);
        $second = new ReceiptResult([new ReceiptEntry(
            'r-1',
            ReceiptState::Returned,
            new PushReceipt('r-1', 'ok', new PushToken(self::TOKEN_A)),
            new PushToken(self::TOKEN_A),
            4,
        )]);

        $merged = $first->merge($second);

        self::assertSame([], $merged->conflicts());
        self::assertSame([1, 4], $merged->entry('r-1')?->notificationIndexes());
        self::assertSame(['r-1'], ReceiptResult::fromStorageArray($merged->toStorageArray())->returnedIds());
    }

    /**
     * A reference without a device joins whatever the entry names.
     */
    public function testAMergeKeepsAReferenceWithNoDevice(): void
    {
        $first = new ReceiptResult([new ReceiptEntry(
            'r-1',
            ReceiptState::Returned,
            new PushReceipt('r-1', 'ok', new PushToken(self::TOKEN_A)),
            new PushToken(self::TOKEN_A),
            1,
        )]);
        $second = new ReceiptResult([new ReceiptEntry('r-1', ReceiptState::Returned, new PushReceipt('r-1', 'ok'))]);

        $merged = $first->merge($second);

        self::assertSame([], $merged->conflicts());
        self::assertSame([1], $merged->entry('r-1')?->notificationIndexes());
    }

    /**
     * A result holds only the failures of its own operation.
     */
    #[DataProvider('failuresOfTheWrongOperation')]
    public function testAFailureOfTheWrongOperationIsRejected(string $target): void
    {
        $sendFailure = new RequestFailure(
            operation: OperationType::Send,
            chunkOrdinal: 0,
            indexes: [4],
            ids: [],
            category: FailureCategory::Transport,
            message: 'boom',
            retryable: true,
        );
        $lookupFailure = new RequestFailure(
            operation: OperationType::Receipts,
            chunkOrdinal: 0,
            indexes: [],
            ids: ['r-9'],
            category: FailureCategory::Transport,
            message: 'boom',
            retryable: true,
        );

        $this->expectException(InvalidStorageException::class);

        if ($target === 'receipts') {
            $stored = (new ReceiptResult([new ReceiptEntry('r-1', ReceiptState::Missing)], [$sendFailure]))
                ->toStorageArray();

            ReceiptResult::fromStorageArray($stored);

            return;
        }

        $outcome = new NotificationOutcome(
            0,
            0,
            0,
            new PushToken(self::TOKEN_A),
            Acceptance::Accepted,
            new PushTicket('ok', 'ticket-1'),
        );

        SendResult::fromStorageArray((new SendResult([$outcome], [$lookupFailure]))->toStorageArray());
    }

    /**
     * @return Generator<string, array{string}>
     */
    public static function failuresOfTheWrongOperation(): Generator
    {
        yield 'a send failure inside a lookup' => ['receipts'];
        yield 'a lookup failure inside a send' => ['send'];
    }

    /**
     * The data of a message keeps one level less than the JSON limit.
     *
     * The message object itself is the reserved level, so a value that passes
     * the copy always encodes inside the payload that carries it.
     */
    public function testTheDataLimitReservesTheLevelOfTheMessage(): void
    {
        self::assertSame(511, PushMessage::MAX_DATA_DEPTH);
        self::assertSame(Json::MAX_DEPTH - 1, PushMessage::MAX_DATA_DEPTH);

        $deep = ['leaf' => 1];

        for ($index = 0; $index < PushMessage::MAX_DATA_DEPTH - 1; ++$index) {
            $deep = ['n' => $deep];
        }

        $message = PushMessage::to(self::TOKEN_A)->data($deep);

        // The whole payload encodes, and the size check runs.
        self::assertGreaterThan(1_000, $message->sizeInBytes());
        self::assertGreaterThan(1_000, strlen(Json::encode($message->jsonSerialize())));
    }

    public function testAReusedObjectAtTheDataLimitFitsAMessage(): void
    {
        $deep = ['leaf' => 1];

        for ($index = 0; $index < PushMessage::MAX_DATA_DEPTH - 2; ++$index) {
            $deep = ['n' => $deep];
        }

        $inner = JsonObject::from($deep);
        $message = PushMessage::to(self::TOKEN_A)->data(['wrap' => $inner]);

        self::assertSame(PushMessage::MAX_DATA_DEPTH - 1, $inner->depth());
        self::assertGreaterThan(1_000, $message->sizeInBytes());
    }

    /**
     * `keys()` gives back the names that the JSON holds.
     */
    public function testKeysGivesBackStrings(): void
    {
        $object = JsonObject::from((object) ['0' => 'a', '12' => 'b', 'name' => 'c']);

        // PHP turns "0" and "12" into integer array keys. `keys()` casts them
        // back, and assertSame compares the types as well as the values.
        self::assertSame(['0', '12', 'name'], $object->keys());
        self::assertSame(['string', 'string', 'string'], array_map('get_debug_type', $object->keys()));
        // The raw array still holds the integer keys that PHP made.
        self::assertSame([0, 12, 'name'], array_keys($object->toArray()));
    }

    /**
     * Nothing above changed what a whole send stores and reads back.
     */
    public function testTheWholeChainStillRoundTrips(): void
    {
        $http = new FakeHttpClient();
        $http->queue(['data' => [['status' => 'ok', 'id' => 'ticket-0']]]);
        $http->queue(['data' => [
            ['status' => 'error', 'message' => 'bad key', 'details' => ['error' => 'InvalidCredentials']],
        ]]);
        $http->queueRaw('slow down', 429, ['Retry-After' => '30']);

        $result = $this->expo($http, sendChunkSize: 1)->send(
            PushMessage::to(self::tokens(4))->title('Hi')->data((object) ['0' => 'first', 'order' => 7])
        );

        $json = json_encode($result->toStorageArray(), JSON_THROW_ON_ERROR);

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $restored = SendResult::fromStorageArray($decoded);

        self::assertSame($result->summary(), $restored->summary());
        self::assertSame('ticket-0', $restored->outcome(0)?->receiptId());
        self::assertSame([1, 2, 3], $restored->recoverable()->indexes());
        self::assertCount(1, $restored->recoverable()->needsIntervention());
        self::assertCount(2, $restored->recoverable()->retryable());
    }

    /**
     * A receipt reference of another device never reaches a lookup plan.
     */
    public function testTwoDevicesForOneIdStillRaiseBeforeALookup(): void
    {
        $http = new FakeHttpClient();

        $this->expectException(InvalidMessageException::class);

        try {
            self::ignoreResult($this->expo($http)->receipts([
                new ReceiptReference('r-1', new PushToken(self::TOKEN_A)),
                new ReceiptReference('r-1', new PushToken(self::TOKEN_B)),
            ]));
        } finally {
            self::assertSame(0, $http->requestCount());
        }
    }
}
