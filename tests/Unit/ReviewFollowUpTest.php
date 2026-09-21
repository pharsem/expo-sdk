<?php

declare(strict_types=1);

namespace Expo\Push\Tests\Unit;

use Expo\Push\ErrorClassification;
use Expo\Push\Exception\InvalidMessageException;
use Expo\Push\Exception\InvalidStorageException;
use Expo\Push\PushError;
use Expo\Push\PushMessage;
use Expo\Push\PushReceipt;
use Expo\Push\PushTicket;
use Expo\Push\PushToken;
use Expo\Push\Result\Acceptance;
use Expo\Push\Result\FailureCategory;
use Expo\Push\Result\NotAcceptedReason;
use Expo\Push\Result\NotificationOutcome;
use Expo\Push\Result\OperationType;
use Expo\Push\Result\ReceiptEntry;
use Expo\Push\Result\ReceiptReference;
use Expo\Push\Result\ReceiptResult;
use Expo\Push\Result\ReceiptState;
use Expo\Push\Result\RecoveryDisposition;
use Expo\Push\Result\RequestFailure;
use Expo\Push\Result\SendResult;
use Expo\Push\Support\Json;
use Expo\Push\Support\JsonObject;
use Expo\Push\Tests\Support\FakeHttpClient;
use Expo\Push\Tests\Support\TestCase;
use Generator;
use PHPUnit\Framework\Attributes\DataProvider;
use stdClass;

/**
 * The holes that the review of this patch found, and the rules that close them.
 *
 * Each test names the property that broke. The first group is about recovery,
 * the second about stored evidence, and the third about the immutable data.
 */
final class ReviewFollowUpTest extends TestCase
{
    /**
     * A credential error keeps the token valid, so the work stays open.
     */
    #[DataProvider('credentialCodes')]
    public function testACredentialTicketNeedsAFixAndStaysRecoverable(string $code): void
    {
        $http = (new FakeHttpClient())->queue(['data' => [
            ['status' => 'error', 'message' => 'bad key', 'details' => ['error' => $code]],
        ]]);

        $result = $this->expo($http)->send(PushMessage::to(self::TOKEN_A)->title('Hi'));
        $outcome = $result->outcomes()[0];

        self::assertSame(Acceptance::NotAccepted, $outcome->acceptance);
        self::assertSame(ErrorClassification::Credentials, $outcome->ticket?->classification());
        self::assertSame(RecoveryDisposition::NeedsIntervention, $outcome->recovery);
        self::assertSame([0], $result->recoverable()->indexes());
        self::assertCount(1, $result->recoverable()->needsIntervention());
        self::assertSame([], $result->unregisteredTokens(), 'the token itself stays valid');
    }

    /**
     * @return Generator<string, array{string}>
     */
    public static function credentialCodes(): Generator
    {
        yield 'InvalidCredentials' => ['InvalidCredentials'];
        yield 'InvalidProviderToken' => ['InvalidProviderToken'];
        yield 'MismatchSenderId' => ['MismatchSenderId'];
    }

    /**
     * Only two rejections close the work, and both need another message or
     * another device.
     */
    public function testOnlyADeadTokenAndATooLargePayloadCloseTheWork(): void
    {
        $closed = [];

        foreach (PushError::cases() as $error) {
            if (RecoveryDisposition::forClassification($error->classification()) === RecoveryDisposition::None) {
                $closed[] = $error->value;
            }
        }

        self::assertSame(['DeviceNotRegistered', 'MessageTooBig'], $closed);
        self::assertSame(
            RecoveryDisposition::None,
            RecoveryDisposition::forClassification(null),
            'a ticket with no error leaves nothing open'
        );
    }

    /**
     * A message whose data keys are numbers is a JSON object, and it stays one.
     */
    public function testAnObjectWithNumericKeysSurvivesTheStorageRoundTrip(): void
    {
        $message = PushMessage::to(self::TOKEN_A)
            ->title('Hi')
            ->data((object) ['0' => 'a', '1' => 'b']);

        $json = json_encode($message->toStorageArray(), JSON_THROW_ON_ERROR);
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        self::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        $restored = PushMessage::fromStorageArray($decoded);

        self::assertSame('{"0":"a","1":"b"}', Json::encode($restored->data));
        self::assertSame(
            Json::encode($message->jsonSerialize()),
            Json::encode($restored->jsonSerialize())
        );
    }

    public function testAnArrayOfDataStillRestoresAsAnArray(): void
    {
        $message = PushMessage::to(self::TOKEN_A)->data(['orderId' => 42]);

        $restored = PushMessage::fromStorageArray($message->toStorageArray());

        self::assertSame(['orderId' => 42], $restored->data);
    }

    /**
     * A stored disposition may not claim less than the evidence demands.
     *
     * @param array<string, mixed> $overrides
     */
    #[DataProvider('dishonestDispositions')]
    public function testAStoredDispositionThatContradictsTheEvidenceIsRejected(
        string $acceptance,
        array $overrides,
    ): void {
        $data = [
            'index' => 0,
            'messageKey' => 0,
            'recipientIndex' => 0,
            'token' => self::TOKEN_A,
            'acceptance' => $acceptance,
            'duplicateRisk' => false,
            'recovery' => 'none',
        ];

        $stored = [
            '_v' => 1,
            '_type' => NotificationOutcome::STORAGE_TYPE,
            'data' => [...$data, ...$overrides],
        ];

        $this->expectException(InvalidStorageException::class);

        NotificationOutcome::fromStorageArray($stored);
    }

    /**
     * @return Generator<string, array{string, array<string, mixed>}>
     */
    public static function dishonestDispositions(): Generator
    {
        yield 'work that never went out cannot be finished' => ['not_attempted', []];

        yield 'an uncertain outcome cannot be finished' => ['unknown', ['duplicateRisk' => true]];

        yield 'a request failure cannot be finished' => [
            'not_accepted',
            ['reason' => 'not_transmitted'],
        ];

        yield 'a throttled device cannot be finished' => [
            'not_accepted',
            [
                'reason' => 'rejected',
                'ticket' => [
                    '_v' => 1,
                    '_type' => PushTicket::STORAGE_TYPE,
                    'data' => ['status' => 'error', 'errorCode' => 'MessageRateExceeded'],
                ],
            ],
        ];

        yield 'a dead token cannot stay open' => [
            'not_accepted',
            [
                'reason' => 'rejected',
                'recovery' => 'retryable',
                'ticket' => [
                    '_v' => 1,
                    '_type' => PushTicket::STORAGE_TYPE,
                    'data' => ['status' => 'error', 'errorCode' => 'DeviceNotRegistered'],
                ],
            ],
        ];
    }

    /**
     * A field that is there must be valid. Only a field that no writer emitted
     * falls back to the careful value.
     */
    public function testAnExplicitNullRecoveryIsRejected(): void
    {
        $http = (new FakeHttpClient())->queueRaw('slow down', 429, ['Retry-After' => '120']);

        $stored = $this->expo($http)
            ->send(PushMessage::to(self::TOKEN_A)->title('Hi'))
            ->outcomes()[0]
            ->toStorageArray();

        self::assertIsArray($stored['data']);

        /** @var array<string, mixed> $data */
        $data = $stored['data'];
        $data['recovery'] = null;
        $stored['data'] = $data;

        $this->expectException(InvalidStorageException::class);

        NotificationOutcome::fromStorageArray($stored);
    }

    /**
     * Two answers that give one receipt ID two devices contradict each other.
     */
    public function testTwoDevicesForOneReceiptIdAreAConflict(): void
    {
        $first = new ReceiptResult([
            new ReceiptEntry('r-1', ReceiptState::Returned, new PushReceipt('r-1', 'ok', new PushToken(self::TOKEN_A))),
        ]);
        $second = new ReceiptResult([
            new ReceiptEntry('r-1', ReceiptState::Returned, new PushReceipt('r-1', 'ok', new PushToken(self::TOKEN_B))),
        ]);

        self::assertSame(['r-1'], $first->merge($second)->conflicts());
        self::assertSame(self::TOKEN_A, $first->merge($second)->get('r-1')?->token?->value);
    }

    /**
     * A lookup that knows the device only adds what the other one lacks.
     */
    public function testAKnownDeviceEnrichesAnUnknownOneWithoutAConflict(): void
    {
        $withToken = new ReceiptResult([
            new ReceiptEntry('r-1', ReceiptState::Returned, new PushReceipt('r-1', 'ok', new PushToken(self::TOKEN_A))),
        ]);
        $without = new ReceiptResult([
            new ReceiptEntry('r-1', ReceiptState::Returned, new PushReceipt('r-1', 'ok')),
        ]);

        self::assertSame([], $withToken->merge($without)->conflicts());
        self::assertSame([], $without->merge($withToken)->conflicts());
    }

    /**
     * An error ticket never gives a receipt ID back, even when it holds one.
     */
    public function testAnErrorTicketNeverReportsAReceiptId(): void
    {
        $outcome = new NotificationOutcome(
            0,
            0,
            0,
            new PushToken(self::TOKEN_A),
            Acceptance::Accepted,
            new PushTicket('error', 'id-1'),
        );

        self::assertNull($outcome->receiptId());
        self::assertNull($outcome->receiptReference());
        self::assertFalse((new SendResult([$outcome]))->isCompleteSuccess());
    }

    /**
     * An open ticket outcome brings no request failure, and it still counts.
     */
    public function testNeedsAttentionSeesAnOpenTicketOutcome(): void
    {
        $http = (new FakeHttpClient())->queue(['data' => [
            ['status' => 'error', 'message' => 'too fast', 'details' => ['error' => 'MessageRateExceeded']],
        ]]);

        $result = $this->expo($http)->send(PushMessage::to(self::TOKEN_A)->title('Hi'));

        self::assertSame([], $result->requestFailures());
        self::assertSame(1, $result->recoverable()->count());
        self::assertTrue($result->needsAttention(), 'the two answers must agree');
    }

    public function testACompleteSuccessStillNeedsNoAttention(): void
    {
        $http = (new FakeHttpClient())->queue(['data' => self::okTickets(2)]);

        $result = $this->expo($http)->send(PushMessage::to([self::TOKEN_A, self::TOKEN_B])->title('Hi'));

        self::assertFalse($result->needsAttention());
        self::assertTrue($result->isCompleteSuccess());
        self::assertTrue($result->recoverable()->isEmpty());
    }

    /**
     * An outcome that points at evidence which is not there never loads.
     *
     * @param callable(array<string, mixed>): array<string, mixed> $corrupt
     */
    #[DataProvider('brokenCrossReferences')]
    public function testAnOutcomeThatPointsAtMissingEvidenceIsRejected(callable $corrupt): void
    {
        $http = new FakeHttpClient();
        $http->queueRaw('slow down', 429, ['Retry-After' => '30']);

        $stored = $this->expo($http, sendChunkSize: 1)
            ->send(PushMessage::to(self::tokens(2))->title('Hi'))
            ->toStorageArray();

        self::assertIsArray($stored['data']);

        /** @var array<string, mixed> $data */
        $data = $stored['data'];
        $stored['data'] = $corrupt($data);

        $this->expectException(InvalidStorageException::class);

        SendResult::fromStorageArray($stored);
    }

    /**
     * @return Generator<string, array{callable(array<string, mixed>): array<string, mixed>}>
     */
    public static function brokenCrossReferences(): Generator
    {
        yield 'a failure index outside the list' => [static function (array $data): array {
            self::assertIsArray($data['outcomes']);
            /** @var list<array<string, mixed>> $outcomes */
            $outcomes = $data['outcomes'];
            self::assertIsArray($outcomes[0]['data']);
            /** @var array<string, mixed> $inner */
            $inner = $outcomes[0]['data'];
            $inner['failureIndex'] = 9;
            $outcomes[0]['data'] = $inner;
            $data['outcomes'] = $outcomes;

            return $data;
        }];

        yield 'a failure that never held this notification' => [static function (array $data): array {
            self::assertIsArray($data['requestFailures']);
            /** @var list<array<string, mixed>> $failures */
            $failures = $data['requestFailures'];
            self::assertIsArray($failures[0]['data']);
            /** @var array<string, mixed> $inner */
            $inner = $failures[0]['data'];
            $inner['indexes'] = [7];
            $failures[0]['data'] = $inner;
            $data['requestFailures'] = $failures;

            return $data;
        }];

        yield 'no failures at all' => [static function (array $data): array {
            $data['requestFailures'] = [];

            return $data;
        }];
    }

    public function testALookupEntryThatPointsAtMissingEvidenceIsRejected(): void
    {
        $entry = new ReceiptEntry('r-1', ReceiptState::LookupFailed, null, null, null, null, 0);
        $failure = new RequestFailure(
            operation: OperationType::Receipts,
            chunkOrdinal: 0,
            indexes: [],
            ids: ['r-2'],
            category: FailureCategory::Transport,
            message: 'boom',
        );

        $stored = (new ReceiptResult([$entry], [$failure]))->toStorageArray();

        $this->expectException(InvalidStorageException::class);
        $this->expectExceptionMessage('never asked about it');

        ReceiptResult::fromStorageArray($stored);
    }

    /**
     * Every later reference of one entry asks about the same ID and device.
     *
     * @param array<string, mixed> $reference
     */
    #[DataProvider('brokenLaterReferences')]
    public function testALaterReferenceOfAnotherNotificationIsRejected(array $reference): void
    {
        $entry = new ReceiptEntry('r-1', ReceiptState::Missing, null, new PushToken(self::TOKEN_A), 1, 'first');
        $stored = $entry->toStorageArray();

        self::assertIsArray($stored['data']);

        /** @var array<string, mixed> $data */
        $data = $stored['data'];
        $data['otherReferences'] = [[
            '_v' => 1,
            '_type' => ReceiptReference::STORAGE_TYPE,
            'data' => $reference,
        ]];
        $stored['data'] = $data;

        $this->expectException(InvalidStorageException::class);

        ReceiptEntry::fromStorageArray($stored);
    }

    /**
     * @return Generator<string, array{array<string, mixed>}>
     */
    public static function brokenLaterReferences(): Generator
    {
        yield 'another receipt ID' => [['id' => 'r-2', 'notificationIndex' => 5]];
        yield 'another device' => [['id' => 'r-1', 'token' => self::TOKEN_B]];
        yield 'a negative position' => [['id' => 'r-1', 'notificationIndex' => -1]];
        yield 'a position of the wrong type' => [['id' => 'r-1', 'notificationIndex' => '5']];
        yield 'a correlation of the wrong type' => [['id' => 'r-1', 'reference' => 5]];
    }

    public function testAValidLaterReferenceStillReads(): void
    {
        $entry = new ReceiptEntry(
            'r-1',
            ReceiptState::Missing,
            null,
            new PushToken(self::TOKEN_A),
            1,
            'first',
            null,
            [new ReceiptReference('r-1', new PushToken(self::TOKEN_A), 4, 'second')],
        );

        $restored = ReceiptEntry::fromStorageArray($entry->toStorageArray());

        self::assertSame([1, 4], $restored->notificationIndexes());
    }

    /**
     * A corrupted retry time must not read as "retry now".
     *
     * @param array<string, mixed> $overrides
     */
    #[DataProvider('brokenFailureFields')]
    public function testAFailureFieldOfTheWrongTypeIsRejected(array $overrides): void
    {
        $failure = new RequestFailure(
            operation: OperationType::Send,
            chunkOrdinal: 0,
            indexes: [0],
            ids: [],
            category: FailureCategory::RateLimited,
            message: 'slow down',
            httpStatus: 429,
            transportCode: null,
            retryable: true,
            deferred: true,
            earliestRetryAtUtcMs: 1_700_000_120_000,
        );

        $stored = $failure->toStorageArray();

        self::assertIsArray($stored['data']);

        /** @var array<string, mixed> $data */
        $data = $stored['data'];
        $stored['data'] = [...$data, ...$overrides];

        $this->expectException(InvalidStorageException::class);

        RequestFailure::fromStorageArray($stored);
    }

    /**
     * @return Generator<string, array{array<string, mixed>}>
     */
    public static function brokenFailureFields(): Generator
    {
        yield 'a retry time as a string' => [['earliestRetryAtUtcMs' => '1700000120000']];
        yield 'a status as a string' => [['httpStatus' => '429']];
        yield 'a transport code as a number' => [['transportCode' => 7]];
    }

    public function testAValidDeferredFailureKeepsItsRetryTime(): void
    {
        $failure = new RequestFailure(
            operation: OperationType::Send,
            chunkOrdinal: 0,
            indexes: [0],
            ids: [],
            category: FailureCategory::RateLimited,
            message: 'slow down',
            httpStatus: 429,
            retryable: true,
            deferred: true,
            earliestRetryAtUtcMs: 1_700_000_120_000,
        );

        $restored = RequestFailure::fromStorageArray($failure->toStorageArray());

        self::assertSame(1_700_000_120_000, $restored->earliestRetryAtUtcMs);
        self::assertSame(429, $restored->httpStatus);
    }

    /**
     * PHP allows a property name that is not valid UTF-8. JSON does not.
     */
    public function testAPropertyNameThatIsNotValidUtf8Raises(): void
    {
        $data = new stdClass();
        $name = "bad\xB1\x31";
        $data->{$name} = 'value';

        $this->expectException(InvalidMessageException::class);
        $this->expectExceptionMessage('property name that is not valid UTF-8');

        self::ignoreResult(PushMessage::to(self::TOKEN_A)->data($data));
    }

    public function testANestedPropertyNameThatIsNotValidUtf8AlsoRaises(): void
    {
        $inner = new stdClass();
        $name = "bad\xB1\x31";
        $inner->{$name} = 'value';

        $this->expectException(InvalidMessageException::class);

        self::ignoreResult(PushMessage::to(self::TOKEN_A)->data(['nested' => $inner]));
    }

    /**
     * The internal factory refuses anything that a later change could alter.
     *
     * @param array<string, mixed> $values
     */
    #[DataProvider('unsafeWrappedValues')]
    public function testTheInternalFactoryRefusesAnUnsafeValue(array $values): void
    {
        $this->expectException(InvalidMessageException::class);

        self::ignoreResult(JsonObject::fromNormalized($values));
    }

    /**
     * @return Generator<string, array{array<string, mixed>}>
     */
    public static function unsafeWrappedValues(): Generator
    {
        yield 'a live object' => [['inner' => new stdClass()]];
        yield 'a live object inside an array' => [['list' => [(object) ['n' => 1]]]];
        yield 'a value that is not JSON' => [['closure' => static fn (): int => 1]];
        yield 'a number that JSON has no value for' => [['nan' => NAN]];
        yield 'a string that is not valid UTF-8' => [['text' => "\xB1\x31"]];
    }

    public function testTheInternalFactoryRefusesExcessiveDepth(): void
    {
        $deep = ['leaf' => 1];

        for ($index = 0; $index < Json::MAX_DEPTH + 10; ++$index) {
            $deep = ['n' => $deep];
        }

        $this->expectException(InvalidMessageException::class);
        $this->expectExceptionMessage('nests deeper than');

        self::ignoreResult(JsonObject::fromNormalized($deep));
    }

    /**
     * A wrapper around a live object can no longer reach a message.
     */
    public function testAWrappedLiveObjectNeverReachesAMessage(): void
    {
        $mutable = new stdClass();
        $mutable->n = 1;

        try {
            $wrapped = JsonObject::fromNormalized(['inner' => $mutable]);
            self::ignoreResult(PushMessage::to(self::TOKEN_A)->data($wrapped));
            self::fail('A message accepted a wrapper around a live object.');
        } catch (InvalidMessageException $exception) {
            self::assertStringContainsString('immutable JSON values', $exception->getMessage());
        }
    }

    public function testTheCheckedFactoryStillAcceptsValidData(): void
    {
        $object = JsonObject::from(['a' => 1, 'nested' => ['b' => true], 'empty' => new stdClass()]);
        $message = PushMessage::to(self::TOKEN_A)->data($object);

        self::assertSame('{"a":1,"nested":{"b":true},"empty":{}}', Json::encode($message->data));
    }

    /**
     * The one limit that the comparison of two receipts cannot see.
     *
     * The receipt parser turns every nested object into an array before the
     * SDK stores it, so a nested empty object and a nested empty list are the
     * same value by the time a merge compares them. This test states that
     * limit, so that nobody reads the rule as a promise.
     */
    public function testANestedEmptyObjectAndAnEmptyListAreTheSameAfterParsing(): void
    {
        $object = Json::entryToArray((object) ['details' => (object) ['x' => new stdClass()]]);
        $list = Json::entryToArray((object) ['details' => (object) ['x' => []]]);

        self::assertSame($object, $list);
        self::assertTrue(Json::sameJson($object, $list));

        // The comparison itself does keep the difference, for the values that
        // still hold it.
        self::assertFalse(Json::sameJson((object) ['x' => new stdClass()], (object) ['x' => []]));
        self::assertFalse(Json::sameJson(['x' => 1], (object) ['x' => 1]));
    }

    /**
     * A restored 429 still carries the guidance that a worker needs.
     */
    public function testTheWholeChainStillRoundTripsAfterTheStricterRules(): void
    {
        $http = new FakeHttpClient();
        $http->queue(['data' => [['status' => 'ok', 'id' => 'ticket-0']]]);
        $http->queueRaw('slow down', 429, ['Retry-After' => '30']);

        $result = $this->expo($http, sendChunkSize: 1)->send(PushMessage::to(self::tokens(3))->title('Hi'));

        $json = json_encode($result->toStorageArray(), JSON_THROW_ON_ERROR);
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        self::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        $restored = SendResult::fromStorageArray($decoded);

        self::assertSame($result->summary(), $restored->summary());
        self::assertSame([1, 2], $restored->recoverable()->indexes());
        self::assertTrue($restored->needsAttention());
        self::assertSame('ticket-0', $restored->outcome(0)?->receiptId());
        self::assertSame(
            $this->clock->nowUtcMillis() + 30_000,
            $restored->recoverable()->earliestRetryAtUtcMs
        );
        self::assertSame(NotAcceptedReason::Rejected, $restored->outcome(1)?->reason);
    }
}
