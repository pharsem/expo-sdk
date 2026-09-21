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
use Expo\Push\Support\Json;
use Expo\Push\Support\JsonObject;
use Expo\Push\Tests\Support\FakeHttpClient;
use Expo\Push\Tests\Support\SlowLimiter;
use Expo\Push\Tests\Support\TestCase;
use Generator;
use PHPUnit\Framework\Attributes\DataProvider;
use stdClass;

/**
 * The second round of review findings, and the rules that close them.
 *
 * Every test here starts from a value that the SDK itself would never write,
 * and asks the reader to refuse it.
 */
final class ReviewFollowUpSecondTest extends TestCase
{
    /**
     * A reused object carries its own levels into the value that holds it.
     */
    public function testANestedReusedObjectCountsItsOwnDepth(): void
    {
        $deep = ['leaf' => 1];

        for ($index = 0; $index < Json::MAX_DEPTH - 1; ++$index) {
            $deep = ['n' => $deep];
        }

        $inner = JsonObject::from($deep);

        self::assertSame(Json::MAX_DEPTH, $inner->depth());
        // On its own it is valid, and it encodes.
        self::assertGreaterThan(1_000, strlen(Json::encode($inner)));

        $this->expectException(InvalidMessageException::class);
        $this->expectExceptionMessage('nests deeper than 511 levels');

        self::ignoreResult(PushMessage::to(self::TOKEN_A)->data(['wrap' => $inner]));
    }

    public function testAReusedObjectThatStillFitsIsAccepted(): void
    {
        $deep = ['leaf' => 1];

        for ($index = 0; $index < 8; ++$index) {
            $deep = ['n' => $deep];
        }

        $inner = JsonObject::from($deep);
        $message = PushMessage::to(self::TOKEN_A)->data(['wrap' => $inner]);

        self::assertSame(9, $inner->depth());
        self::assertStringStartsWith('{"wrap":{"n":', Json::encode($message->data));
    }

    /**
     * A limiter that blocks past the deadline reports the deadline, and keeps
     * the moment that the limiter asked for.
     */
    public function testALimiterDenialAfterTheDeadlineReportsTheDeadline(): void
    {
        $http = new FakeHttpClient();
        $limiter = new SlowLimiter($this->clock, 500, denyForMs: 5_000);

        $result = $this->expo($http, rateLimiter: $limiter, bucket: 'p', operationDeadlineMs: 100)
            ->send(PushMessage::to(self::TOKEN_A)->title('Hi'));

        $failure = $result->requestFailures()[0];

        self::assertCount(1, $limiter->calls);
        self::assertSame(0, $http->requestCount());
        self::assertSame(FailureCategory::Deadline, $failure->category);
        self::assertTrue($failure->deferred);
        // The limiter asked for five seconds from the moment of its answer.
        self::assertSame($this->clock->nowUtcMillis() + 5_000, $failure->earliestRetryAtUtcMs);
        self::assertSame(Acceptance::NotAttempted, $result->outcomes()[0]->acceptance);
    }

    /**
     * A limiter denial inside the deadline still reports the rate limit.
     */
    public function testALimiterDenialInsideTheDeadlineStillReportsTheRateLimit(): void
    {
        $http = new FakeHttpClient();
        $limiter = new SlowLimiter($this->clock, 10, denyForMs: 5_000);

        $result = $this->expo($http, rateLimiter: $limiter, bucket: 'p', operationDeadlineMs: 60_000)
            ->send(PushMessage::to(self::TOKEN_A)->title('Hi'));

        self::assertSame(FailureCategory::RateLimited, $result->requestFailures()[0]->category);
    }

    /**
     * The stored disposition of a rejected device must match its error code.
     *
     * @param array<string, mixed> $ticket
     */
    #[DataProvider('dispositionsThatContradictATicket')]
    public function testAStoredDispositionMustMatchTheTicketCode(
        string $acceptance,
        array $ticket,
        string $recovery,
    ): void {
        $stored = [
            '_v' => 1,
            '_type' => NotificationOutcome::STORAGE_TYPE,
            'data' => [
                'index' => 0,
                'messageKey' => 0,
                'recipientIndex' => 0,
                'token' => self::TOKEN_A,
                'acceptance' => $acceptance,
                'reason' => $acceptance === 'not_accepted' ? 'rejected' : null,
                'duplicateRisk' => $acceptance === 'unknown',
                'recovery' => $recovery,
                'ticket' => [
                    '_v' => 1,
                    '_type' => PushTicket::STORAGE_TYPE,
                    'data' => $ticket,
                ],
            ],
        ];

        $this->expectException(InvalidStorageException::class);

        NotificationOutcome::fromStorageArray($stored);
    }

    /**
     * @return Generator<string, array{string, array<string, mixed>, string}>
     */
    public static function dispositionsThatContradictATicket(): Generator
    {
        yield 'a credential failure read as retryable' => [
            'not_accepted',
            ['status' => 'error', 'errorCode' => 'InvalidCredentials'],
            'retryable',
        ];

        yield 'a throttled device read as needing a fix' => [
            'not_accepted',
            ['status' => 'error', 'errorCode' => 'MessageRateExceeded'],
            'needs_intervention',
        ];

        yield 'an accepted notification read as retryable' => [
            'accepted',
            ['status' => 'ok', 'id' => 'ticket-1'],
            'retryable',
        ];

        yield 'an uncertain throttled device read as needing a fix' => [
            'unknown',
            ['status' => 'error', 'errorCode' => 'MessageRateExceeded'],
            'needs_intervention',
        ];
    }

    public function testAStoredDispositionThatMatchesTheTicketStillReads(): void
    {
        $http = (new FakeHttpClient())->queue(['data' => [
            ['status' => 'error', 'message' => 'bad key', 'details' => ['error' => 'InvalidCredentials']],
        ]]);

        $stored = $this->expo($http)
            ->send(PushMessage::to(self::TOKEN_A)->title('Hi'))
            ->outcomes()[0]
            ->toStorageArray();

        self::assertSame(
            \Expo\Push\Result\RecoveryDisposition::NeedsIntervention,
            NotificationOutcome::fromStorageArray($stored)->recovery
        );
    }

    /**
     * A nested reader may not weaken the evidence that it holds.
     *
     * @param array<string, mixed> $overrides
     */
    #[DataProvider('brokenTicketFields')]
    public function testATicketFieldOfTheWrongTypeIsRejected(array $overrides): void
    {
        $stored = [
            '_v' => 1,
            '_type' => PushTicket::STORAGE_TYPE,
            'data' => [...['status' => 'error', 'errorCode' => 'DeviceNotRegistered'], ...$overrides],
        ];

        $this->expectException(InvalidStorageException::class);

        PushTicket::fromStorageArray($stored);
    }

    /**
     * @return Generator<string, array{array<string, mixed>}>
     */
    public static function brokenTicketFields(): Generator
    {
        yield 'an error code as a number' => [['errorCode' => 7]];
        yield 'a message as a number' => [['message' => 7]];
        yield 'a token as a number' => [['token' => 7]];
        yield 'a token of the wrong shape' => [['token' => 'not-a-token']];
    }

    /**
     * @param array<string, mixed> $overrides
     */
    #[DataProvider('brokenReceiptFields')]
    public function testAReceiptFieldOfTheWrongTypeIsRejected(array $overrides): void
    {
        $stored = [
            '_v' => 1,
            '_type' => PushReceipt::STORAGE_TYPE,
            'data' => [...['id' => 'r-1', 'status' => 'error', 'errorCode' => 'DeviceNotRegistered'], ...$overrides],
        ];

        $this->expectException(InvalidStorageException::class);

        PushReceipt::fromStorageArray($stored);
    }

    /**
     * @return Generator<string, array{array<string, mixed>}>
     */
    public static function brokenReceiptFields(): Generator
    {
        yield 'an error code as a number' => [['errorCode' => 7]];
        yield 'a message as a number' => [['message' => 7]];
        yield 'a token of the wrong shape' => [['token' => 'not-a-token']];
    }

    /**
     * A broken error code once turned a dead token into open work.
     */
    public function testABrokenErrorCodeNeverTurnsADeadTokenIntoOpenWork(): void
    {
        $http = (new FakeHttpClient())->queue(['data' => [
            ['status' => 'error', 'message' => 'gone', 'details' => ['error' => 'DeviceNotRegistered']],
        ]]);

        $stored = $this->expo($http)->send(PushMessage::to(self::TOKEN_A)->title('Hi'))->toStorageArray();

        self::assertIsArray($stored['data']);

        /** @var array<string, mixed> $data */
        $data = $stored['data'];
        self::assertIsArray($data['outcomes']);

        /** @var list<array<string, mixed>> $outcomes */
        $outcomes = $data['outcomes'];
        self::assertIsArray($outcomes[0]['data']);

        /** @var array<string, mixed> $outcome */
        $outcome = $outcomes[0]['data'];
        self::assertIsArray($outcome['ticket']);

        /** @var array<string, mixed> $ticket */
        $ticket = $outcome['ticket'];
        self::assertIsArray($ticket['data']);

        /** @var array<string, mixed> $inner */
        $inner = $ticket['data'];
        $inner['errorCode'] = 7;
        $ticket['data'] = $inner;
        $outcome['ticket'] = $ticket;
        $outcomes[0]['data'] = $outcome;
        $data['outcomes'] = $outcomes;
        $stored['data'] = $data;

        $this->expectException(InvalidStorageException::class);

        SendResult::fromStorageArray($stored);
    }

    /**
     * Every part of one entry names the same device, and the entry itself does
     * not have to hold the token.
     */
    public function testAnEntryWhoseReceiptAndReferenceDisagreeIsRejected(): void
    {
        $entry = new ReceiptEntry(
            'r-1',
            ReceiptState::Returned,
            new PushReceipt('r-1', 'ok', new PushToken(self::TOKEN_A)),
            null,
            null,
            null,
            null,
            [new ReceiptReference('r-1', new PushToken(self::TOKEN_B))],
        );

        $this->expectException(InvalidStorageException::class);
        $this->expectExceptionMessage('names two devices');

        ReceiptEntry::fromStorageArray($entry->toStorageArray());
    }

    public function testTwoLaterReferencesOfTwoDevicesAreRejected(): void
    {
        $entry = new ReceiptEntry(
            'r-1',
            ReceiptState::Missing,
            null,
            null,
            null,
            null,
            null,
            [
                new ReceiptReference('r-1', new PushToken(self::TOKEN_A)),
                new ReceiptReference('r-1', new PushToken(self::TOKEN_B)),
            ],
        );

        $this->expectException(InvalidStorageException::class);

        ReceiptEntry::fromStorageArray($entry->toStorageArray());
    }

    public function testAnEntryWithOneDeviceInEveryPartStillReads(): void
    {
        $entry = new ReceiptEntry(
            'r-1',
            ReceiptState::Returned,
            new PushReceipt('r-1', 'ok', new PushToken(self::TOKEN_A)),
            new PushToken(self::TOKEN_A),
            2,
            'order-2',
            null,
            [new ReceiptReference('r-1', new PushToken(self::TOKEN_A), 5, 'order-5')],
        );

        $restored = ReceiptEntry::fromStorageArray($entry->toStorageArray());

        self::assertSame([2, 5], $restored->notificationIndexes());
        self::assertSame(self::TOKEN_A, $restored->token?->value);
    }

    /**
     * A stored token of the wrong shape raises a storage error, not a token
     * error.
     */
    public function testABrokenStoredTokenRaisesAStorageError(): void
    {
        $stored = [
            '_v' => 1,
            '_type' => ReceiptReference::STORAGE_TYPE,
            'data' => ['id' => 'r-1', 'token' => 'not-a-token'],
        ];

        $this->expectException(InvalidStorageException::class);

        ReceiptReference::fromStorageArray($stored);
    }

    /**
     * Every request of the SDK holds at least one notification or one ID.
     */
    #[DataProvider('emptyFailureCollections')]
    public function testAFailureWithNoWorkIsRejected(OperationType $operation): void
    {
        $failure = new RequestFailure(
            operation: $operation,
            chunkOrdinal: 0,
            indexes: [],
            ids: [],
            category: FailureCategory::Transport,
            message: 'boom',
        );

        $this->expectException(InvalidStorageException::class);

        RequestFailure::fromStorageArray($failure->toStorageArray());
    }

    /**
     * @return Generator<string, array{OperationType}>
     */
    public static function emptyFailureCollections(): Generator
    {
        yield 'a send that holds no notification' => [OperationType::Send];
        yield 'a lookup that holds no receipt ID' => [OperationType::Receipts];
    }

    /**
     * A failure that names work which no outcome points back at never loads.
     */
    public function testAFailureThatNoOutcomePointsAtIsRejected(): void
    {
        $outcome = new NotificationOutcome(
            0,
            0,
            0,
            new PushToken(self::TOKEN_A),
            Acceptance::Accepted,
            new PushTicket('ok', 'ticket-1'),
        );

        $failure = new RequestFailure(
            operation: OperationType::Send,
            chunkOrdinal: 0,
            indexes: [0],
            ids: [],
            category: FailureCategory::Transport,
            message: 'boom',
            retryable: true,
        );

        $stored = (new SendResult([$outcome], [$failure]))->toStorageArray();

        $this->expectException(InvalidStorageException::class);
        $this->expectExceptionMessage('no outcome');

        SendResult::fromStorageArray($stored);
    }

    /**
     * The same rule cannot hold for a lookup, and a merged result shows why.
     *
     * A failed lookup and then a successful one keep the failure as the record
     * of the first request, while the entry reports the receipt. The entry
     * points at no failure any more, and the result is correct.
     */
    public function testAMergedLookupKeepsAFailureThatNoEntryPointsAt(): void
    {
        $failed = new ReceiptResult(
            [new ReceiptEntry('r-1', ReceiptState::LookupFailed, null, null, null, null, 0)],
            [new RequestFailure(
                operation: OperationType::Receipts,
                chunkOrdinal: 0,
                indexes: [],
                ids: ['r-1'],
                category: FailureCategory::Transport,
                message: 'boom',
                retryable: true,
            )],
        );
        $returned = new ReceiptResult([
            new ReceiptEntry('r-1', ReceiptState::Returned, new PushReceipt('r-1', 'ok')),
        ]);

        $merged = $failed->merge($returned);

        self::assertSame(ReceiptState::Returned, $merged->state('r-1'));
        self::assertNull($merged->entry('r-1')?->failureIndex);
        self::assertCount(1, $merged->requestFailures());
        self::assertSame(['r-1'], $merged->requestFailures()[0]->ids);

        // So the reader keeps the one-way check for a lookup.
        $restored = ReceiptResult::fromStorageArray($merged->toStorageArray());

        self::assertSame(ReceiptState::Returned, $restored->state('r-1'));
        self::assertCount(1, $restored->requestFailures());
    }

    /**
     * The SDK writes 1 and 1.0 as the same JSON, so they are the same value.
     */
    public function testTwoNumbersThatEncodeTheSameAreNotAConflict(): void
    {
        $first = new ReceiptResult([new ReceiptEntry(
            'r-1',
            ReceiptState::Returned,
            new PushReceipt('r-1', 'error', null, 'boom', null, 'ProviderError', ['tries' => 1, 'ratio' => 0.5]),
        )]);
        $second = new ReceiptResult([new ReceiptEntry(
            'r-1',
            ReceiptState::Returned,
            new PushReceipt('r-1', 'error', null, 'boom', null, 'ProviderError', ['tries' => 1.0, 'ratio' => 0.5]),
        )]);

        self::assertSame('1', Json::encode(1));
        self::assertSame('1', Json::encode(1.0));
        self::assertSame([], $first->merge($second)->conflicts());
    }

    public function testTwoNumbersThatEncodeDifferentlyStayAConflict(): void
    {
        $first = new ReceiptResult([new ReceiptEntry(
            'r-1',
            ReceiptState::Returned,
            new PushReceipt('r-1', 'error', null, 'boom', null, 'ProviderError', ['tries' => 1]),
        )]);
        $second = new ReceiptResult([new ReceiptEntry(
            'r-1',
            ReceiptState::Returned,
            new PushReceipt('r-1', 'error', null, 'boom', null, 'ProviderError', ['tries' => 1.5]),
        )]);

        self::assertSame(['r-1'], $first->merge($second)->conflicts());
        self::assertFalse(Json::sameJson(1, 1.5));
        self::assertFalse(Json::sameJson(1, '1'), 'a number is not a string');
        self::assertTrue(Json::sameJson(1, 1.0));
    }

    /**
     * The whole chain still round trips under every rule above.
     */
    public function testTheWholeChainStillRoundTrips(): void
    {
        $http = new FakeHttpClient();
        $http->queue(['data' => [['status' => 'ok', 'id' => 'ticket-0']]]);
        $http->queue(['data' => [
            ['status' => 'error', 'message' => 'gone', 'details' => ['error' => 'DeviceNotRegistered']],
        ]]);
        $http->queueRaw('slow down', 429, ['Retry-After' => '30']);

        $result = $this->expo($http, sendChunkSize: 1)->send(
            PushMessage::to(self::tokens(4))->title('Hi')->data((object) ['orderId' => 7])
        );

        $json = json_encode($result->toStorageArray(), JSON_THROW_ON_ERROR);
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        self::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        $restored = SendResult::fromStorageArray($decoded);

        self::assertSame($result->summary(), $restored->summary());
        self::assertSame('ticket-0', $restored->outcome(0)?->receiptId());
        self::assertSame([2, 3], $restored->recoverable()->indexes());
        self::assertTrue($restored->needsAttention());
    }

    /**
     * The documented limit of a round trip through an associative decode.
     *
     * A PHP array cannot hold the difference between a nested `{}` and a
     * nested `[]`, so an associative decode loses it. A decode into objects
     * keeps it.
     */
    public function testAMessageWithObjectDataRoundTripsWithinTheDocumentedLimit(): void
    {
        $message = PushMessage::to(self::TOKEN_A)
            ->title('Hi')
            ->data((object) ['orderId' => 7, 'nested' => (object) ['n' => 1.0], 'empty' => new stdClass()]);

        $json = json_encode($message->toStorageArray(), JSON_THROW_ON_ERROR);

        self::assertStringContainsString('"empty":{}', $json);

        /** @var array<string, mixed> $asArrays */
        $asArrays = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(
            '{"orderId":7,"nested":{"n":1},"empty":[]}',
            Json::encode(PushMessage::fromStorageArray($asArrays)->data),
            'an associative decode cannot hold a nested empty object'
        );

        // A decode into objects keeps every shape. The envelope itself is an
        // array, so a caller converts those two levels and leaves the message
        // data as it came.
        $decoded = json_decode($json, false, 512, JSON_THROW_ON_ERROR);

        self::assertInstanceOf(stdClass::class, $decoded);

        $envelope = Json::objectToArray($decoded);

        self::assertInstanceOf(stdClass::class, $envelope['data']);

        $envelope['data'] = Json::objectToArray($envelope['data']);

        self::assertSame(
            '{"orderId":7,"nested":{"n":1},"empty":{}}',
            Json::encode(PushMessage::fromStorageArray($envelope)->data)
        );
    }
}
