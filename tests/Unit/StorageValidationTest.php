<?php

declare(strict_types=1);

namespace Expo\Push\Tests\Unit;

use Expo\Push\Exception\InvalidStorageException;
use Expo\Push\Http\TransportFailureKind;
use Expo\Push\PushMessage;
use Expo\Push\Result\Acceptance;
use Expo\Push\Result\NotificationOutcome;
use Expo\Push\Result\ReceiptEntry;
use Expo\Push\Result\ReceiptResult;
use Expo\Push\Result\RecoveryDisposition;
use Expo\Push\Result\SendResult;
use Expo\Push\Retry\NoRetryPolicy;
use Expo\Push\Tests\Support\FakeHttpClient;
use Expo\Push\Tests\Support\TestCase;
use Generator;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Stored evidence decides what a restored result may claim.
 *
 * The reviewed reader accepted an accepted outcome with no ticket, turned a
 * broken index into zero, and read a missing duplicate risk as "no risk". A
 * worker then read a plausible success that nothing supported.
 */
final class StorageValidationTest extends TestCase
{
    /**
     * A full result with every state, as the starting point of the corruption
     * tests below.
     */
    private function storedResult(): SendResult
    {
        $http = new FakeHttpClient();
        $http->queue(['data' => [['status' => 'ok', 'id' => 'ticket-0']]]);
        $http->queue(['data' => [
            ['status' => 'error', 'message' => 'gone', 'details' => ['error' => 'DeviceNotRegistered']],
        ]]);
        $http->queueFailure(TransportFailureKind::Timeout);

        // The timeout on chunk 2 stops the run, so chunk 3 never starts.
        return $this->expo($http, new NoRetryPolicy(), sendChunkSize: 1)
            ->send(PushMessage::to(self::tokens(4))->title('Hi')->reference('batch'));
    }

    /**
     * @return array<string, mixed>
     */
    private function storedOutcome(int $index): array
    {
        $outcome = $this->storedResult()->outcome($index);

        self::assertNotNull($outcome);

        return $outcome->toStorageArray();
    }

    public function testAValidResultStillRoundTripsWithEveryState(): void
    {
        $result = $this->storedResult();

        $json = json_encode($result->toStorageArray(), JSON_THROW_ON_ERROR);
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        self::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        $restored = SendResult::fromStorageArray($decoded);

        self::assertSame($result->summary(), $restored->summary());
        self::assertSame('ticket-0', $restored->outcome(0)?->receiptId());
        self::assertSame(Acceptance::NotAccepted, $restored->outcome(1)?->acceptance);
        self::assertSame(Acceptance::Unknown, $restored->outcome(2)?->acceptance);
        self::assertSame(Acceptance::NotAttempted, $restored->outcome(3)?->acceptance);
        self::assertSame($result->recoverable()->indexes(), $restored->recoverable()->indexes());
    }

    /**
     * @param callable(array<string, mixed>): array<string, mixed> $corrupt
     */
    #[DataProvider('outcomeCorruptions')]
    public function testACorruptStoredOutcomeIsRejected(int $index, callable $corrupt): void
    {
        $stored = $this->storedOutcome($index);

        self::assertIsArray($stored['data']);

        /** @var array<string, mixed> $data */
        $data = $stored['data'];
        $stored['data'] = $corrupt($data);

        $this->expectException(InvalidStorageException::class);

        NotificationOutcome::fromStorageArray($stored);
    }

    /**
     * @return Generator<string, array{int, callable(array<string, mixed>): array<string, mixed>}>
     */
    public static function outcomeCorruptions(): Generator
    {
        yield 'an accepted outcome without a ticket' => [0, static function (array $data): array {
            unset($data['ticket']);

            return $data;
        }];

        yield 'an accepted outcome with a null ticket' => [0, static function (array $data): array {
            $data['ticket'] = null;

            return $data;
        }];

        yield 'an accepted outcome whose ticket reports an error' => [0, static function (array $data): array {
            self::assertIsArray($data['ticket']);
            /** @var array<string, mixed> $ticket */
            $ticket = $data['ticket'];
            self::assertIsArray($ticket['data']);
            /** @var array<string, mixed> $inner */
            $inner = $ticket['data'];
            $inner['status'] = 'error';
            unset($inner['id']);
            $ticket['data'] = $inner;
            $data['ticket'] = $ticket;

            return $data;
        }];

        yield 'an accepted outcome whose ticket lost its id' => [0, static function (array $data): array {
            self::assertIsArray($data['ticket']);
            /** @var array<string, mixed> $ticket */
            $ticket = $data['ticket'];
            self::assertIsArray($ticket['data']);
            /** @var array<string, mixed> $inner */
            $inner = $ticket['data'];
            unset($inner['id']);
            $ticket['data'] = $inner;
            $data['ticket'] = $ticket;

            return $data;
        }];

        yield 'a missing index' => [0, static function (array $data): array {
            unset($data['index']);

            return $data;
        }];

        yield 'an index of the wrong type' => [0, static function (array $data): array {
            $data['index'] = '2';

            return $data;
        }];

        yield 'a negative index' => [0, static function (array $data): array {
            $data['index'] = -1;

            return $data;
        }];

        yield 'a missing recipient index' => [0, static function (array $data): array {
            unset($data['recipientIndex']);

            return $data;
        }];

        yield 'a missing message key' => [0, static function (array $data): array {
            unset($data['messageKey']);

            return $data;
        }];

        yield 'a message key of the wrong type' => [0, static function (array $data): array {
            $data['messageKey'] = 1.5;

            return $data;
        }];

        yield 'a missing duplicate risk flag' => [2, static function (array $data): array {
            unset($data['duplicateRisk']);

            return $data;
        }];

        yield 'a duplicate risk flag of the wrong type' => [2, static function (array $data): array {
            $data['duplicateRisk'] = 'yes';

            return $data;
        }];

        yield 'a missing token' => [0, static function (array $data): array {
            unset($data['token']);

            return $data;
        }];

        yield 'a missing acceptance' => [0, static function (array $data): array {
            unset($data['acceptance']);

            return $data;
        }];

        yield 'an acceptance that this SDK never writes' => [0, static function (array $data): array {
            $data['acceptance'] = 'probably';

            return $data;
        }];

        yield 'a recovery value that this SDK never writes' => [0, static function (array $data): array {
            $data['recovery'] = 'maybe';

            return $data;
        }];

        yield 'a reason that this SDK never writes' => [1, static function (array $data): array {
            $data['reason'] = 'because';

            return $data;
        }];

        yield 'a rejection without a reason' => [1, static function (array $data): array {
            unset($data['reason']);

            return $data;
        }];

        yield 'an accepted outcome that also names a reason' => [0, static function (array $data): array {
            $data['reason'] = 'rejected';

            return $data;
        }];

        yield 'work that was never attempted and holds a ticket' => [3, static function (array $data): array {
            $data['ticket'] = [
                '_v' => 1,
                '_type' => 'expo.ticket',
                'data' => ['status' => 'ok', 'id' => 'invented'],
            ];

            return $data;
        }];

        yield 'work that was never attempted and claims a duplicate risk' => [3, static function (array $data): array {
            $data['duplicateRisk'] = true;

            return $data;
        }];

        yield 'a ticket of another device' => [0, static function (array $data): array {
            self::assertIsArray($data['ticket']);
            /** @var array<string, mixed> $ticket */
            $ticket = $data['ticket'];
            self::assertIsArray($ticket['data']);
            /** @var array<string, mixed> $inner */
            $inner = $ticket['data'];
            $inner['token'] = 'ExponentPushToken[zzzzzzzzzzzzzzzzzzzzzz]';
            $ticket['data'] = $inner;
            $data['ticket'] = $ticket;

            return $data;
        }];
    }

    /**
     * A broken outcome inside a result stops the whole read. The reader never
     * drops it and answers with the rest.
     */
    public function testABrokenOutcomeInsideAResultStopsTheWholeRead(): void
    {
        $stored = $this->storedResult()->toStorageArray();

        self::assertIsArray($stored['data']);
        /** @var array<string, mixed> $data */
        $data = $stored['data'];
        self::assertIsArray($data['outcomes']);
        /** @var list<array<string, mixed>> $outcomes */
        $outcomes = $data['outcomes'];
        self::assertIsArray($outcomes[1]['data']);
        /** @var array<string, mixed> $inner */
        $inner = $outcomes[1]['data'];
        unset($inner['duplicateRisk']);
        $outcomes[1]['data'] = $inner;
        $data['outcomes'] = $outcomes;
        $stored['data'] = $data;

        $this->expectException(InvalidStorageException::class);

        SendResult::fromStorageArray($stored);
    }

    /**
     * @param callable(array<string, mixed>): array<string, mixed> $corrupt
     */
    #[DataProvider('failureCorruptions')]
    public function testACorruptStoredRequestFailureIsRejected(callable $corrupt): void
    {
        $stored = $this->storedResult()->toStorageArray();

        self::assertIsArray($stored['data']);
        /** @var array<string, mixed> $data */
        $data = $stored['data'];
        self::assertIsArray($data['requestFailures']);
        /** @var list<array<string, mixed>> $failures */
        $failures = $data['requestFailures'];
        self::assertIsArray($failures[0]['data']);
        /** @var array<string, mixed> $inner */
        $inner = $failures[0]['data'];
        $failures[0]['data'] = $corrupt($inner);
        $data['requestFailures'] = $failures;
        $stored['data'] = $data;

        $this->expectException(InvalidStorageException::class);

        SendResult::fromStorageArray($stored);
    }

    /**
     * @return Generator<string, array{callable(array<string, mixed>): array<string, mixed>}>
     */
    public static function failureCorruptions(): Generator
    {
        yield 'a missing chunk ordinal' => [static function (array $data): array {
            unset($data['chunkOrdinal']);

            return $data;
        }];

        yield 'a missing message' => [static function (array $data): array {
            unset($data['message']);

            return $data;
        }];

        yield 'a missing retryable flag' => [static function (array $data): array {
            unset($data['retryable']);

            return $data;
        }];

        yield 'a missing deferred flag' => [static function (array $data): array {
            unset($data['deferred']);

            return $data;
        }];

        yield 'an index of the wrong type' => [static function (array $data): array {
            $data['indexes'] = ['2'];

            return $data;
        }];

        yield 'a send failure that names receipt IDs' => [static function (array $data): array {
            $data['ids'] = ['r-1'];

            return $data;
        }];

        yield 'an attempt without a number' => [static function (array $data): array {
            self::assertIsArray($data['attempts']);
            /** @var list<array<string, mixed>> $attempts */
            $attempts = $data['attempts'];
            unset($attempts[0]['number']);
            $data['attempts'] = $attempts;

            return $data;
        }];

        yield 'an attempt with a result that this SDK never writes' => [static function (array $data): array {
            self::assertIsArray($data['attempts']);
            /** @var list<array<string, mixed>> $attempts */
            $attempts = $data['attempts'];
            $attempts[0]['result'] = 'maybe';
            $data['attempts'] = $attempts;

            return $data;
        }];
    }

    /**
     * A valid history is not a contradiction. An accepted result may carry an
     * earlier ambiguous attempt and a duplicate risk.
     */
    public function testAnAcceptedOutcomeWithADuplicateRiskRoundTrips(): void
    {
        $http = new FakeHttpClient();
        $http->queueFailure(TransportFailureKind::Timeout);
        $http->queue(['data' => self::okTickets(1)]);

        $result = $this->expo($http)->send(PushMessage::to(self::TOKEN_A)->title('Hi'));

        $restored = SendResult::fromStorageArray($result->toStorageArray());
        $outcome = $restored->outcome(0);

        self::assertNotNull($outcome);
        self::assertSame(Acceptance::Accepted, $outcome->acceptance);
        self::assertTrue($outcome->duplicateRisk);
        self::assertSame('ticket-0', $outcome->receiptId());
        self::assertSame(RecoveryDisposition::None, $outcome->recovery);
        self::assertTrue($restored->isCompleteSuccess());
    }

    /**
     * An unknown result may keep the final rejection that came after an
     * earlier ambiguous attempt.
     */
    public function testAnUnknownOutcomeThatKeepsAFinalRejectionRoundTrips(): void
    {
        $http = new FakeHttpClient();
        $http->queueFailure(TransportFailureKind::Timeout);
        $http->queue(['data' => [
            ['status' => 'error', 'message' => 'gone', 'details' => ['error' => 'DeviceNotRegistered']],
        ]]);

        $result = $this->expo($http)->send(PushMessage::to(self::TOKEN_A)->title('Hi'));

        $restored = SendResult::fromStorageArray($result->toStorageArray());
        $outcome = $restored->outcome(0);

        self::assertNotNull($outcome);
        self::assertSame(Acceptance::Unknown, $outcome->acceptance);
        self::assertTrue($outcome->duplicateRisk);
        self::assertSame('DeviceNotRegistered', $outcome->ticket?->errorCode);
        self::assertNull($outcome->receiptId());
        self::assertFalse($restored->isCompleteSuccess());
    }

    /**
     * An error code that this SDK does not know is data, not a broken state.
     */
    public function testAnUnknownProviderErrorCodeStillRoundTrips(): void
    {
        $http = (new FakeHttpClient())->queue(['data' => [
            [
                'status' => 'error',
                'message' => 'something new',
                'details' => ['error' => 'SomethingBrandNew', 'extra' => ['a' => 1]],
            ],
        ]]);

        $result = $this->expo($http)->send(PushMessage::to(self::TOKEN_A)->title('Hi'));
        $restored = SendResult::fromStorageArray($result->toStorageArray());

        $ticket = $restored->outcome(0)?->ticket;

        self::assertNotNull($ticket);
        self::assertSame('SomethingBrandNew', $ticket->errorCode);
        self::assertNull($ticket->error);
        self::assertSame(['error' => 'SomethingBrandNew', 'extra' => ['a' => 1]], $ticket->details);
    }

    /**
     * A restored result never reports a success that its evidence cannot show.
     */
    public function testACompleteSuccessNeedsARealReceiptId(): void
    {
        $http = (new FakeHttpClient())->queue(['data' => self::okTickets(2)]);

        $result = $this->expo($http)->send(PushMessage::to([self::TOKEN_A, self::TOKEN_B])->title('Hi'));

        self::assertTrue($result->isCompleteSuccess());

        // A malformed entry never becomes a success, before or after storage.
        $second = (new FakeHttpClient())->queueRaw('{"data": ["garbage", {"status": "ok", "id": "t2"}]}');
        $partial = $this->expo($second)->send(PushMessage::to([self::TOKEN_A, self::TOKEN_B])->title('Hi'));

        self::assertFalse($partial->isCompleteSuccess());
        self::assertFalse(SendResult::fromStorageArray($partial->toStorageArray())->isCompleteSuccess());
    }

    public function testAnUnresolvedLookupRoundTrips(): void
    {
        $http = new FakeHttpClient();
        $http->queue(['data' => ['r-0' => ['status' => 'ok']]]);
        $http->queueFailure(TransportFailureKind::Timeout);

        $result = $this->expo($http, new NoRetryPolicy(), continueAfterFailure: true, receiptChunkSize: 1)
            ->receipts(['r-0', 'r-1']);

        $restored = ReceiptResult::fromStorageArray($result->toStorageArray());

        self::assertSame(['r-0'], $restored->returnedIds());
        self::assertSame(['r-1'], $restored->failedIds());
        self::assertSame($result->summary(), $restored->summary());
    }

    public function testAStoredReceiptEntryOfAnotherIdIsRejected(): void
    {
        $entry = new ReceiptEntry(
            'r-1',
            \Expo\Push\Result\ReceiptState::Returned,
            new \Expo\Push\PushReceipt('r-1', 'ok')
        );

        $stored = $entry->toStorageArray();

        self::assertIsArray($stored['data']);
        /** @var array<string, mixed> $data */
        $data = $stored['data'];
        $data['id'] = 'r-2';
        $stored['data'] = $data;

        $this->expectException(InvalidStorageException::class);
        $this->expectExceptionMessage('receipt with another ID');

        ReceiptEntry::fromStorageArray($stored);
    }

    public function testAStoredReceiptEntryOfAnotherDeviceIsRejected(): void
    {
        $entry = new ReceiptEntry(
            'r-1',
            \Expo\Push\Result\ReceiptState::Returned,
            new \Expo\Push\PushReceipt('r-1', 'ok', new \Expo\Push\PushToken(self::TOKEN_A)),
            new \Expo\Push\PushToken(self::TOKEN_B),
        );

        $this->expectException(InvalidStorageException::class);
        $this->expectExceptionMessage('names two devices');

        ReceiptEntry::fromStorageArray($entry->toStorageArray());
    }

    public function testAStoredReceiptEntryWithABrokenIndexIsRejected(): void
    {
        $entry = new ReceiptEntry('r-1', \Expo\Push\Result\ReceiptState::Missing, null, null, 3);

        $stored = $entry->toStorageArray();

        self::assertIsArray($stored['data']);
        /** @var array<string, mixed> $data */
        $data = $stored['data'];
        $data['notificationIndex'] = '3';
        $stored['data'] = $data;

        $this->expectException(InvalidStorageException::class);

        ReceiptEntry::fromStorageArray($stored);
    }
}
