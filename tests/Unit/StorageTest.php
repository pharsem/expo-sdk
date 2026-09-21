<?php

declare(strict_types=1);

namespace Expo\Push\Tests\Unit;

use Expo\Push\Exception\InvalidStorageException;
use Expo\Push\Http\TransportFailureKind;
use Expo\Push\PushMessage;
use Expo\Push\PushReceipt;
use Expo\Push\PushTicket;
use Expo\Push\PushToken;
use Expo\Push\ReceiptCollection;
use Expo\Push\Result\Acceptance;
use Expo\Push\Result\ReceiptReference;
use Expo\Push\Result\ReceiptResult;
use Expo\Push\Result\ReceiptState;
use Expo\Push\Result\SendResult;
use Expo\Push\Storage\StorageEnvelope;
use Expo\Push\Tests\Support\FakeHttpClient;
use Expo\Push\Tests\Support\TestCase;
use Expo\Push\TicketCollection;

final class StorageTest extends TestCase
{
    /**
     * Sends the array through JSON, the way a queue or a database does.
     *
     * @param array<string, mixed> $stored
     *
     * @return array<string, mixed>
     */
    private static function roundTrip(array $stored): array
    {
        $json = json_encode($stored, JSON_THROW_ON_ERROR);
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        self::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    public function testATicketKeepsItsTokenThroughStorage(): void
    {
        $ticket = new PushTicket('ok', 'r1', new PushToken(self::TOKEN_A));

        $restored = PushTicket::fromStorageArray(self::roundTrip($ticket->toStorageArray()));

        self::assertSame('r1', $restored->id);
        self::assertSame(self::TOKEN_A, $restored->token?->value);
        self::assertTrue($restored->isOk());
    }

    public function testAnErrorTicketKeepsItsUnknownCodeAndDetails(): void
    {
        $ticket = new PushTicket(
            status: 'error',
            token: new PushToken(self::TOKEN_B),
            message: 'boom',
            errorCode: 'SomethingNew',
            details: ['error' => 'SomethingNew', 'extra' => ['a' => 1]],
        );

        $restored = PushTicket::fromStorageArray(self::roundTrip($ticket->toStorageArray()));

        self::assertSame('SomethingNew', $restored->errorCode);
        self::assertNull($restored->error);
        self::assertSame(['error' => 'SomethingNew', 'extra' => ['a' => 1]], $restored->details);
        self::assertSame('boom', $restored->message);
    }

    public function testAReceiptRoundTrips(): void
    {
        $receipt = new PushReceipt(
            id: 'r1',
            status: 'error',
            token: new PushToken(self::TOKEN_A),
            message: 'gone',
            error: \Expo\Push\PushError::DeviceNotRegistered,
            errorCode: 'DeviceNotRegistered',
            details: ['error' => 'DeviceNotRegistered'],
        );

        $restored = PushReceipt::fromStorageArray(self::roundTrip($receipt->toStorageArray()));

        self::assertSame('r1', $restored->id);
        self::assertTrue($restored->invalidatesToken());
        self::assertSame(self::TOKEN_A, $restored->token?->value);
    }

    public function testAReceiptReferenceRoundTrips(): void
    {
        $reference = new ReceiptReference('r1', new PushToken(self::TOKEN_A), 7, 'order-9');

        $restored = ReceiptReference::fromStorageArray(self::roundTrip($reference->toStorageArray()));

        self::assertSame('r1', $restored->id);
        self::assertSame(self::TOKEN_A, $restored->token?->value);
        self::assertSame(7, $restored->notificationIndex);
        self::assertSame('order-9', $restored->reference);
    }

    public function testAReferenceWithoutATokenRoundTrips(): void
    {
        $restored = ReceiptReference::fromStorageArray(self::roundTrip((new ReceiptReference('r1'))->toStorageArray()));

        self::assertNull($restored->token);
        self::assertNull($restored->notificationIndex);
    }

    public function testAMessageRoundTrips(): void
    {
        $message = new PushMessage(
            to: [self::TOKEN_A, self::TOKEN_B],
            title: 'Title',
            body: 'Body',
            data: ['orderId' => 42, 'nested' => ['a' => 1]],
            sound: 'bells.wav',
            ttl: 0,
            badge: 0,
            mutableContent: false,
            collapseId: 'order-42',
            relevanceScore: 0.25,
            reference: 'correlation-1',
        );

        $restored = PushMessage::fromStorageArray(self::roundTrip($message->toStorageArray()));

        self::assertSame($message->toExpoArray(), $restored->toExpoArray());
        self::assertSame('correlation-1', $restored->reference);
        self::assertSame([self::TOKEN_A, self::TOKEN_B], self::values($restored->to));
        self::assertSame(0, $restored->ttl);
        self::assertSame(0, $restored->badge);
        self::assertFalse($restored->mutableContent);
    }

    public function testASilentMessageRoundTrips(): void
    {
        $message = PushMessage::to(self::TOKEN_A)->silent();

        $restored = PushMessage::fromStorageArray(self::roundTrip($message->toStorageArray()));

        self::assertArrayHasKey('sound', $restored->toExpoArray());
        self::assertNull($restored->toExpoArray()['sound']);
    }

    public function testAMessageWithoutASoundRoundTrips(): void
    {
        $restored = PushMessage::fromStorageArray(
            self::roundTrip(PushMessage::to(self::TOKEN_A)->title('Hi')->toStorageArray())
        );

        self::assertArrayNotHasKey('sound', $restored->toExpoArray());
    }

    public function testACriticalSoundRoundTrips(): void
    {
        $message = PushMessage::to(self::TOKEN_A)->sound(\Expo\Push\Sound::critical('alarm.wav', 0.4));

        $restored = PushMessage::fromStorageArray(self::roundTrip($message->toStorageArray()));

        self::assertSame(
            ['critical' => true, 'name' => 'alarm.wav', 'volume' => 0.4],
            $restored->toExpoArray()['sound']
        );
    }

    public function testAnEmptyDataObjectRoundTrips(): void
    {
        $message = PushMessage::to(self::TOKEN_A)->data([]);

        $restored = PushMessage::fromStorageArray(self::roundTrip($message->toStorageArray()));

        self::assertStringContainsString('"data":{}', json_encode($restored->toExpoArray(), JSON_THROW_ON_ERROR));
    }

    public function testATicketCollectionRoundTrips(): void
    {
        $collection = new TicketCollection([
            new PushTicket('ok', 'r1', new PushToken(self::TOKEN_A)),
            new PushTicket('error', null, new PushToken(self::TOKEN_B), 'gone', null, 'Weird'),
        ]);

        $restored = TicketCollection::fromStorageArray(self::roundTrip($collection->toStorageArray()));

        self::assertCount(2, $restored);
        self::assertSame(['r1'], $restored->ids());
        self::assertSame('Weird', $restored->all()[1]->errorCode);
    }

    public function testAReceiptCollectionRoundTrips(): void
    {
        $collection = new ReceiptCollection([new PushReceipt('r1', 'ok', new PushToken(self::TOKEN_A))]);

        $restored = ReceiptCollection::fromStorageArray(self::roundTrip($collection->toStorageArray()));

        self::assertSame(['r1'], $restored->ids());
        self::assertSame(self::TOKEN_A, $restored->first()?->token?->value);
    }

    public function testASendResultRoundTripsWithEveryState(): void
    {
        $http = new FakeHttpClient();
        $http->queue(['data' => [
            ['status' => 'ok', 'id' => 'r1'],
            ['status' => 'error', 'message' => 'no', 'details' => ['error' => 'Weird']],
        ]]);
        $http->queueFailure(TransportFailureKind::Timeout);
        $http->queueFailure(TransportFailureKind::Timeout);
        $http->queueFailure(TransportFailureKind::Timeout);

        $result = $this->expo($http, sendChunkSize: 2)
            ->send(PushMessage::to(self::tokens(6))->title('Hi')->reference('batch-1'));

        $restored = SendResult::fromStorageArray(self::roundTrip($result->toStorageArray()));

        self::assertSame($result->summary(), $restored->summary());
        self::assertSame(Acceptance::Accepted, $restored->outcomes()[0]->acceptance);
        self::assertSame('r1', $restored->outcomes()[0]->receiptId());
        self::assertSame(self::tokens(1)[0], $restored->outcomes()[0]->token->value);
        self::assertSame('batch-1', $restored->outcomes()[0]->reference);
        self::assertSame(Acceptance::NotAccepted, $restored->outcomes()[1]->acceptance);
        self::assertSame('Weird', $restored->outcomes()[1]->ticket?->errorCode);
        self::assertSame(Acceptance::Unknown, $restored->outcomes()[2]->acceptance);
        self::assertTrue($restored->outcomes()[2]->duplicateRisk);
        self::assertSame(Acceptance::NotAttempted, $restored->outcomes()[4]->acceptance);
        self::assertCount(2, $restored->requestFailures());
        self::assertSame([2, 3], $restored->requestFailures()[0]->indexRange());
        self::assertSame(3, $restored->requestFailures()[0]->attemptCount());
        self::assertSame('timeout', $restored->requestFailures()[0]->transportCode);
        self::assertCount(1, $restored->receiptReferences());
    }

    public function testAReceiptResultRoundTripsWithEveryState(): void
    {
        $http = new FakeHttpClient();
        $http->queue(['data' => ['r1' => ['status' => 'ok'], 'r2' => 'garbage']]);
        $http->queueRaw('boom', 500);

        $result = $this->expo($http, new \Expo\Push\Retry\NoRetryPolicy(), receiptChunkSize: 3)
            ->receipts(['r1', 'r2', 'r3', 'r4', 'r5']);

        $restored = ReceiptResult::fromStorageArray(self::roundTrip($result->toStorageArray()));

        self::assertSame($result->summary(), $restored->summary());
        self::assertSame(['r1'], $restored->returnedIds());
        self::assertSame(['r2'], $restored->malformedIds());
        self::assertSame(['r3'], $restored->missingIds());
        self::assertSame(['r4', 'r5'], $restored->failedIds());
        self::assertSame(ReceiptState::Malformed, $restored->state('r2'));
        self::assertCount(1, $restored->requestFailures());
    }

    public function testTheStoredResultHoldsNoLiveObject(): void
    {
        $http = (new FakeHttpClient())->queueFailure(TransportFailureKind::Timeout);

        $result = $this->expo($http, new \Expo\Push\Retry\NoRetryPolicy())->send(PushMessage::to(self::TOKEN_A));

        $json = json_encode($result->toStorageArray(), JSON_THROW_ON_ERROR);

        self::assertStringNotContainsString('Exception', $json);
        self::assertStringNotContainsString('Closure', $json);
        self::assertStringNotContainsString('Resource', $json);
    }

    public function testAnUnknownSchemaVersionIsRejected(): void
    {
        $stored = (new ReceiptReference('r1'))->toStorageArray();
        $stored[StorageEnvelope::VERSION_KEY] = 99;

        $this->expectException(InvalidStorageException::class);
        $this->expectExceptionMessage('schema version 99');

        ReceiptReference::fromStorageArray($stored);
    }

    public function testAWrongTypeIsRejected(): void
    {
        $stored = (new ReceiptReference('r1'))->toStorageArray();

        $this->expectException(InvalidStorageException::class);
        $this->expectExceptionMessage('expo.ticket');

        PushTicket::fromStorageArray($stored);
    }

    public function testAnArrayWithoutAnEnvelopeIsRejected(): void
    {
        $this->expectException(InvalidStorageException::class);
        $this->expectExceptionMessage('does not come from this SDK');

        PushTicket::fromStorageArray(['status' => 'ok', 'id' => 'r1']);
    }

    public function testAMissingFieldIsRejected(): void
    {
        $stored = StorageEnvelope::wrap(PushTicket::STORAGE_TYPE, ['id' => 'r1']);

        $this->expectException(InvalidStorageException::class);
        $this->expectExceptionMessage('status');

        PushTicket::fromStorageArray($stored);
    }

    public function testAFieldOfTheWrongTypeIsRejected(): void
    {
        $stored = StorageEnvelope::wrap(PushTicket::STORAGE_TYPE, ['status' => 'ok', 'details' => 'not an array']);

        $this->expectException(InvalidStorageException::class);
        $this->expectExceptionMessage('details');

        PushTicket::fromStorageArray($stored);
    }

    public function testTheStoredReferencesAreEnoughForALookupInAnotherProcess(): void
    {
        $http = new FakeHttpClient();
        $http->queue(['data' => [['status' => 'ok', 'id' => 'r1'], ['status' => 'ok', 'id' => 'r2']]]);

        $send = $this->expo($http)->send(PushMessage::to([self::TOKEN_A, self::TOKEN_B])->reference('batch-3'));

        $stored = array_map(
            static fn (ReceiptReference $reference): array => self::roundTrip($reference->toStorageArray()),
            $send->receiptReferences()
        );

        $restored = array_map(
            static fn (array $entry): ReceiptReference => ReceiptReference::fromStorageArray($entry),
            $stored
        );

        $lookup = new FakeHttpClient();
        $lookup->queue(['data' => ['r1' => ['status' => 'ok'], 'r2' => ['status' => 'ok']]]);

        $result = $this->expo($lookup)->receipts($restored);

        self::assertSame(['r1', 'r2'], $result->returnedIds());
        self::assertSame(self::TOKEN_B, $result->entry('r2')?->token?->value);
        self::assertSame('batch-3', $result->entry('r2')->reference);
        self::assertSame(1, $result->entry('r2')->notificationIndex);
    }
}
