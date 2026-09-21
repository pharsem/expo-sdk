<?php

declare(strict_types=1);

namespace Expo\Push\Tests\Unit;

use Expo\Push\Exception\InvalidMessageException;
use Expo\Push\Exception\ReceiptLookupFailedException;
use Expo\Push\Http\TransportFailureKind;
use Expo\Push\PushError;
use Expo\Push\PushMessage;
use Expo\Push\PushReceipt;
use Expo\Push\PushToken;
use Expo\Push\Result\ReceiptEntry;
use Expo\Push\Result\ReceiptReference;
use Expo\Push\Result\ReceiptResult;
use Expo\Push\Result\ReceiptState;
use Expo\Push\Retry\NoRetryPolicy;
use Expo\Push\Tests\Support\FakeHttpClient;
use Expo\Push\Tests\Support\TestCase;

final class ReceiptTest extends TestCase
{
    public function testEveryStateStaysApart(): void
    {
        $http = new FakeHttpClient();
        $http->queue(['data' => [
            'r1' => ['status' => 'ok'],
            'r2' => ['status' => 'error', 'message' => 'no', 'details' => ['error' => 'MessageTooBig']],
            'r4' => 'garbage',
            'zz' => ['status' => 'ok'],
        ]]);
        $http->queueRaw('boom', 500);

        $result = $this->expo($http, new NoRetryPolicy(), receiptChunkSize: 5)
            ->receipts(['r1', 'r2', 'r3', 'r4', 'r5', 'x1']);

        self::assertSame(['r1', 'r2'], $result->returnedIds());
        self::assertSame(['r3', 'r5'], $result->missingIds());
        self::assertSame(['r4'], $result->malformedIds());
        self::assertSame(['x1'], $result->failedIds());
        self::assertSame(['zz'], $result->unexpectedIds());
        self::assertSame([], $result->notAttemptedIds());
        self::assertFalse($result->isComplete());
        self::assertSame(['r3', 'r4', 'r5', 'x1'], $result->unresolvedIds());

        self::assertSame(ReceiptState::Returned, $result->state('r1'));
        self::assertSame(ReceiptState::Malformed, $result->state('r4'));
        self::assertSame(ReceiptState::LookupFailed, $result->state('x1'));
        self::assertSame(PushError::MessageTooBig, $result->get('r2')?->error);
    }

    public function testANotAttemptedLookupIsItsOwnState(): void
    {
        $http = new FakeHttpClient();
        $http->queueRaw('boom', 500);

        $result = $this->expo($http, new NoRetryPolicy(), receiptChunkSize: 1)->receipts(['a', 'b', 'c']);

        self::assertSame(['a'], $result->failedIds());
        self::assertSame(['b', 'c'], $result->notAttemptedIds());
        self::assertSame(1, $http->requestCount());
    }

    public function testFilteringTheReceiptsNeverInventsAMissingId(): void
    {
        $http = (new FakeHttpClient())->queue(['data' => [
            'r1' => ['status' => 'ok'],
            'r2' => ['status' => 'error', 'message' => 'no', 'details' => ['error' => 'DeviceNotRegistered']],
        ]]);

        $result = $this->expo($http)->receipts(['r1', 'r2', 'r3']);

        self::assertSame(['r3'], $result->missingIds());
        self::assertCount(1, $result->errors());
        self::assertCount(1, $result->ok());
        // Reading the errors did not change the coverage of the lookup.
        self::assertSame(['r3'], $result->missingIds());
        self::assertSame(['r1', 'r2'], $result->returnedIds());
        self::assertSame(['r1'], $result->receipts()->ok()->ids());
    }

    public function testALaterLookupResolvesTheMissingStateWithoutLosingTheCorrelation(): void
    {
        $http = new FakeHttpClient();
        $http->queue(['data' => ['r1' => ['status' => 'ok']]]);
        $http->queue(['data' => ['r2' => ['status' => 'ok']]]);

        $expo = $this->expo($http);
        $first = $expo->receipts([
            new ReceiptReference('r1', new PushToken(self::TOKEN_A), 0, 'order-1'),
            new ReceiptReference('r2', new PushToken(self::TOKEN_B), 1, 'order-2'),
        ]);

        self::assertSame(['r2'], $first->missingIds());

        $second = $expo->receipts([new ReceiptReference('r2', new PushToken(self::TOKEN_B), 1, 'order-2')]);
        $merged = $first->merge($second);

        self::assertSame(['r1', 'r2'], $merged->returnedIds());
        self::assertSame([], $merged->missingIds());
        self::assertTrue($merged->isComplete());
        self::assertSame(2, $merged->count());
        self::assertSame(self::TOKEN_B, $merged->entry('r2')?->token?->value);
        self::assertSame('order-2', $merged->entry('r2')->reference);
        self::assertSame(1, $merged->entry('r2')->notificationIndex);
    }

    public function testAReturnedReceiptIsNeverDowngraded(): void
    {
        $returned = new ReceiptResult([
            new ReceiptEntry('r1', ReceiptState::Returned, new PushReceipt('r1', 'ok')),
        ]);
        $missing = new ReceiptResult([new ReceiptEntry('r1', ReceiptState::Missing)]);

        $merged = $returned->merge($missing);

        self::assertSame(ReceiptState::Returned, $merged->state('r1'));
        self::assertSame(1, $merged->count());
    }

    public function testTheSameReceiptTwiceStaysOneEntry(): void
    {
        $one = new ReceiptResult([
            new ReceiptEntry('r1', ReceiptState::Returned, new PushReceipt('r1', 'ok')),
        ]);
        $two = new ReceiptResult([
            new ReceiptEntry('r1', ReceiptState::Returned, new PushReceipt('r1', 'ok')),
        ]);

        $merged = $one->merge($two);

        self::assertSame(1, $merged->count());
        self::assertSame([], $merged->conflicts());
    }

    public function testTwoReceiptsThatDoNotAgreeAreFlagged(): void
    {
        $one = new ReceiptResult([
            new ReceiptEntry('r1', ReceiptState::Returned, new PushReceipt('r1', 'ok')),
        ]);
        $two = new ReceiptResult([
            new ReceiptEntry('r1', ReceiptState::Returned, new PushReceipt('r1', 'error', null, 'gone')),
        ]);

        $merged = $one->merge($two);

        self::assertSame(1, $merged->count());
        self::assertSame(['r1'], $merged->conflicts());
        // The first answer stays. The SDK never overwrites one in silence.
        self::assertTrue($merged->get('r1')?->isOk());
    }

    public function testAFailedLookupIsReplacedByALaterMissingState(): void
    {
        $failed = new ReceiptResult([new ReceiptEntry('r1', ReceiptState::LookupFailed)]);
        $missing = new ReceiptResult([new ReceiptEntry('r1', ReceiptState::Missing)]);

        self::assertSame(ReceiptState::Missing, $failed->merge($missing)->state('r1'));
        self::assertSame(ReceiptState::Missing, $missing->merge($failed)->state('r1'));
    }

    public function testTheLookupCarriesTheTokenFromTheSend(): void
    {
        $http = new FakeHttpClient();
        $http->queue(['data' => [['status' => 'ok', 'id' => 'r1'], ['status' => 'ok', 'id' => 'r2']]]);
        $http->queue(['data' => [
            'r1' => ['status' => 'ok'],
            'r2' => ['status' => 'error', 'message' => 'gone', 'details' => ['error' => 'DeviceNotRegistered']],
        ]]);

        $expo = $this->expo($http);
        $send = $expo->send(PushMessage::to([self::TOKEN_A, self::TOKEN_B])->reference('batch-7'));
        $result = $expo->receipts($send);

        self::assertSame(self::TOKEN_A, $result->entry('r1')?->token?->value);
        self::assertSame('batch-7', $result->entry('r1')->reference);
        self::assertSame([self::TOKEN_B], self::values($result->unregisteredTokens()));
    }

    public function testARawIdHasNoTokenUnlessYouGiveOne(): void
    {
        $http = (new FakeHttpClient())->queue(['data' => ['r1' => ['status' => 'ok']]]);

        $result = $this->expo($http)->receipts('r1');

        self::assertNull($result->entry('r1')?->token);
    }

    public function testARepeatedIdIsAskedForOneTime(): void
    {
        $http = (new FakeHttpClient())->queue(['data' => ['r1' => ['status' => 'ok']]]);

        $result = $this->expo($http)->receipts(['r1', 'r1', 'r1']);

        self::assertSame(['ids' => ['r1']], $http->payload());
        self::assertSame(1, $result->count());
    }

    public function testAConflictingTokenForOneIdRaisesBeforeAnyRequest(): void
    {
        $http = new FakeHttpClient();

        try {
            self::ignoreResult($this->expo($http)->receipts([
                new ReceiptReference('r1', new PushToken(self::TOKEN_A)),
                new ReceiptReference('r1', new PushToken(self::TOKEN_B)),
            ]));
            self::fail('The lookup must raise InvalidMessageException.');
        } catch (InvalidMessageException $exception) {
            self::assertStringContainsString('two different device tokens', $exception->getMessage());
        }

        self::assertSame(0, $http->requestCount());
    }

    public function testARepeatedIdKeepsTheKnownToken(): void
    {
        $http = (new FakeHttpClient())->queue(['data' => ['r1' => ['status' => 'ok']]]);

        $result = $this->expo($http)->receipts([
            new ReceiptReference('r1'),
            new ReceiptReference('r1', new PushToken(self::TOKEN_A), 4, 'order-9'),
        ]);

        self::assertSame(self::TOKEN_A, $result->entry('r1')?->token?->value);
    }

    public function testAnEmptyLookupSendsNothing(): void
    {
        $http = new FakeHttpClient();

        $result = $this->expo($http)->receipts([]);

        self::assertTrue($result->isEmpty());
        self::assertSame(0, $http->requestCount());
    }

    public function testThrowIfLookupFailedCarriesTheWholeResult(): void
    {
        $http = new FakeHttpClient();
        $http->queue(['data' => ['r1' => ['status' => 'ok']]]);
        $http->queueFailure(TransportFailureKind::ConnectFailed);

        $result = $this->expo($http, new NoRetryPolicy(), receiptChunkSize: 1, continueAfterFailure: true)
            ->receipts(['r1', 'r2']);

        try {
            $result->throwIfLookupFailed();
            self::fail('The result must raise ReceiptLookupFailedException.');
        } catch (ReceiptLookupFailedException $exception) {
            self::assertSame($result, $exception->result);
            self::assertSame(['r1'], $exception->result->returnedIds());
            self::assertSame(['r2'], $exception->result->failedIds());
        }
    }

    public function testAnErrorReceiptDoesNotRaise(): void
    {
        $http = (new FakeHttpClient())->queue(['data' => [
            'r1' => ['status' => 'error', 'message' => 'no', 'details' => ['error' => 'MessageTooBig']],
        ]]);

        $result = $this->expo($http)->receipts('r1')->throwIfLookupFailed();

        self::assertTrue($result->hasErrors());
    }

    public function testPendingIdsIsTheSameListAsMissingIds(): void
    {
        $http = (new FakeHttpClient())->queue(['data' => ['r1' => ['status' => 'ok']]]);

        $result = $this->expo($http)->receipts(['r1', 'r2']);

        self::assertSame($result->missingIds(), $result->pendingIds());
    }

    public function testALookupAcceptsASendResultATicketCollectionAndRawIds(): void
    {
        $http = new FakeHttpClient();
        $http->queue(['data' => [['status' => 'ok', 'id' => 'r1']]]);
        $http->queue(['data' => ['r1' => ['status' => 'ok'], 'r9' => ['status' => 'ok']]]);

        $expo = $this->expo($http);
        $send = $expo->send(PushMessage::to(self::TOKEN_A));

        $result = $expo->receipts([$send->tickets(), 'r9']);

        self::assertSame(['r1', 'r9'], $result->returnedIds());
        self::assertSame(self::TOKEN_A, $result->entry('r1')?->token?->value);
        self::assertNull($result->entry('r9')?->token);
    }

    public function testOnlyAnAcceptedTicketProducesAReference(): void
    {
        $http = (new FakeHttpClient())->queue(['data' => [
            ['status' => 'ok', 'id' => 'r1'],
            ['status' => 'error', 'message' => 'no', 'details' => ['error' => 'DeviceNotRegistered']],
        ]]);

        $result = $this->expo($http)->send(PushMessage::to([self::TOKEN_A, self::TOKEN_B]));

        self::assertCount(1, $result->receiptReferences());
        self::assertSame('r1', $result->receiptReferences()[0]->id);
    }
}
