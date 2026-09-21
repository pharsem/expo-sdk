<?php

declare(strict_types=1);

namespace Expo\Push\Tests\Unit;

use Expo\Push\Exception\InvalidStorageException;
use Expo\Push\Expo;
use Expo\Push\Http\RequestFactory;
use Expo\Push\Http\Transmission;
use Expo\Push\Http\TransportFailure;
use Expo\Push\Http\TransportFailureKind;
use Expo\Push\PushReceipt;
use Expo\Push\PushToken;
use Expo\Push\RateLimit\SlidingWindowRateLimiter;
use Expo\Push\Result\ReceiptEntry;
use Expo\Push\Result\ReceiptReference;
use Expo\Push\Result\ReceiptResult;
use Expo\Push\Result\ReceiptState;
use Expo\Push\Result\SendResult;
use Expo\Push\Retry\RetryAfter;
use Expo\Push\Storage\StorageEnvelope;
use Expo\Push\Tests\Support\FakeHttpClient;
use Expo\Push\Tests\Support\RecordingLimiter;
use Expo\Push\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The findings of the review of pull request 1, one test for each.
 */
final class ReviewRegressionTest extends TestCase
{
    /**
     * Expo counts notifications, and a receipt lookup sends none. A lookup of
     * 1000 IDs would never fit a limiter of 600 permits either.
     */
    public function testTheNotificationLimiterNeverBoundsAReceiptLookup(): void
    {
        $http = new FakeHttpClient();
        $http->queue(['data' => []]);

        $limiter = new SlidingWindowRateLimiter(600, 1_000, $this->clock);
        $ids = array_map(static fn (int $index): string => 'r' . $index, range(1, 700));

        $result = $this->expo($http, rateLimiter: $limiter, bucket: 'project')->receipts($ids);

        self::assertSame(1, $http->requestCount());
        self::assertSame([], $result->requestFailures());
        self::assertSame(700, count($result->missingIds()));
        self::assertSame(600, $limiter->available('project'));
    }

    public function testAReceiptLookupAsksTheLimiterForNothing(): void
    {
        $http = (new FakeHttpClient())->queue(['data' => ['r1' => ['status' => 'ok']]]);
        $limiter = new RecordingLimiter();

        $result = $this->expo($http, rateLimiter: $limiter, bucket: 'project')->receipts(['r1']);

        self::assertSame(['r1'], $result->returnedIds());
        self::assertSame([], $limiter->calls);
    }

    public function testASendStillAsksTheLimiter(): void
    {
        $http = (new FakeHttpClient())->queue(['data' => self::okTickets(1)]);
        $limiter = new RecordingLimiter();

        $result = $this->expo($http, rateLimiter: $limiter, bucket: 'project')
            ->send(\Expo\Push\PushMessage::to(self::TOKEN_A));

        self::assertCount(1, $result->accepted());
        self::assertSame([1], $limiter->permits());
    }

    /**
     * A byte counter above zero can only move the answer toward Unknown.
     */
    public function testUploadedBytesStopAClaimThatNothingLeftTheProcess(): void
    {
        $withBytes = TransportFailure::of(TransportFailureKind::ConnectFailed, 'failed', 'CURLE_7', 512);
        $withoutBytes = TransportFailure::of(TransportFailureKind::ConnectFailed, 'failed', 'CURLE_7', 0);
        $unknownBytes = TransportFailure::of(TransportFailureKind::ConnectFailed, 'failed', 'CURLE_7');

        self::assertSame(Transmission::Unknown, $withBytes->transmission());
        self::assertSame(Transmission::NotTransmitted, $withoutBytes->transmission());
        self::assertSame(Transmission::NotTransmitted, $unknownBytes->transmission());
    }

    public function testATruncatedStoredResultRaisesInsteadOfLosingTheOutcomes(): void
    {
        $stored = StorageEnvelope::wrap(SendResult::STORAGE_TYPE, [
            'uncorrelatedIds' => [],
            'warnings' => [],
        ]);

        $this->expectException(InvalidStorageException::class);
        $this->expectExceptionMessage('outcomes');

        SendResult::fromStorageArray($stored);
    }

    public function testATruncatedStringListAlsoRaises(): void
    {
        $stored = StorageEnvelope::wrap(SendResult::STORAGE_TYPE, [
            'outcomes' => [],
            'requestFailures' => [],
            'warnings' => [],
        ]);

        $this->expectException(InvalidStorageException::class);
        $this->expectExceptionMessage('uncorrelatedIds');

        SendResult::fromStorageArray($stored);
    }

    public function testACompleteStoredResultStillReads(): void
    {
        $stored = StorageEnvelope::wrap(SendResult::STORAGE_TYPE, [
            'outcomes' => [],
            'requestFailures' => [],
            'uncorrelatedIds' => [],
            'warnings' => [],
        ]);

        self::assertSame(0, SendResult::fromStorageArray($stored)->count());
    }

    /**
     * `notify(data: [])` means the same as `PushMessage::data([])`.
     */
    public function testNotifyKeepsAnExplicitlyEmptyDataObject(): void
    {
        $http = (new FakeHttpClient())->queue(['data' => self::okTickets(1)]);

        $result = $this->expo($http)->notify(self::TOKEN_A, 'Hi', null, []);

        self::assertCount(1, $result->accepted());
        self::assertStringContainsString('"data":{}', $http->requests[0]->body);
    }

    public function testNotifyWithoutDataSendsNoDataKey(): void
    {
        $http = (new FakeHttpClient())->queue(['data' => self::okTickets(1)]);

        $result = $this->expo($http)->notify(self::TOKEN_A, 'Hi');

        self::assertCount(1, $result->accepted());
        self::assertStringNotContainsString('"data"', $http->requests[0]->body);
    }

    /**
     * @return iterable<string, array{0: bool, 1: bool, 2: string}>
     */
    public static function encodings(): iterable
    {
        yield 'the transport decodes' => [true, false, 'gzip, deflate'];
        yield 'zlib decodes' => [false, true, 'gzip, deflate'];
        yield 'both decode' => [true, true, 'gzip, deflate'];
        yield 'nothing decodes' => [false, false, 'identity'];
    }

    #[DataProvider('encodings')]
    public function testTheSdkAsksOnlyForAnEncodingThatItCanRead(
        bool $transportDecodes,
        bool $zlibAvailable,
        string $expected,
    ): void {
        self::assertSame($expected, RequestFactory::negotiateAcceptEncoding($transportDecodes, $zlibAvailable));
    }

    public function testTheCurlTransportGetsTheCompressedEncoding(): void
    {
        $http = (new FakeHttpClient(new \Expo\Push\Http\TransportCapabilities(1, false, true)))
            ->queue(['data' => self::okTickets(1)]);

        $result = $this->expo($http)->send(\Expo\Push\PushMessage::to(self::TOKEN_A));

        self::assertCount(1, $result->accepted());
        self::assertSame('gzip, deflate', $http->headers()['accept-encoding']);
    }

    /**
     * A merge keeps the correlation of both entries, whichever state wins.
     */
    public function testAMergeKeepsTheCorrelationOfTheLaterEntry(): void
    {
        $first = new ReceiptResult([new ReceiptEntry('r1', ReceiptState::Missing)]);
        $second = new ReceiptResult([
            new ReceiptEntry(
                'r1',
                ReceiptState::Returned,
                new PushReceipt('r1', 'ok'),
                new PushToken(self::TOKEN_A),
                7,
                'order-9',
            ),
        ]);

        $entry = $first->merge($second)->entry('r1');

        self::assertInstanceOf(ReceiptEntry::class, $entry);
        self::assertSame(ReceiptState::Returned, $entry->state);
        self::assertSame(self::TOKEN_A, $entry->token?->value);
        self::assertSame(7, $entry->notificationIndex);
        self::assertSame('order-9', $entry->reference);
    }

    public function testAMergeKeepsTheCorrelationWhenTheEarlierStateWins(): void
    {
        $first = new ReceiptResult([
            new ReceiptEntry('r1', ReceiptState::Returned, new PushReceipt('r1', 'ok')),
        ]);
        $second = new ReceiptResult([
            new ReceiptEntry('r1', ReceiptState::Missing, null, new PushToken(self::TOKEN_B), 3, 'order-3'),
        ]);

        $entry = $first->merge($second)->entry('r1');

        self::assertInstanceOf(ReceiptEntry::class, $entry);
        self::assertSame(ReceiptState::Returned, $entry->state);
        self::assertSame(self::TOKEN_B, $entry->token?->value);
        self::assertSame(3, $entry->notificationIndex);
        self::assertSame('order-3', $entry->reference);
    }

    public function testAMergeOfTwoReturnedEntriesAlsoKeepsTheCorrelation(): void
    {
        $first = new ReceiptResult([
            new ReceiptEntry('r1', ReceiptState::Returned, new PushReceipt('r1', 'ok')),
        ]);
        $second = new ReceiptResult([
            new ReceiptEntry(
                'r1',
                ReceiptState::Returned,
                new PushReceipt('r1', 'ok'),
                new PushToken(self::TOKEN_C),
                4,
                'order-4',
            ),
        ]);

        $merged = $first->merge($second);
        $entry = $merged->entry('r1');

        self::assertSame(1, $merged->count());
        self::assertSame([], $merged->conflicts());
        self::assertInstanceOf(ReceiptEntry::class, $entry);
        self::assertSame(self::TOKEN_C, $entry->token?->value);
        self::assertSame('order-4', $entry->reference);
    }

    public function testAnEarlierCorrelationWinsOverALaterOne(): void
    {
        $first = new ReceiptResult([
            new ReceiptEntry('r1', ReceiptState::Missing, null, new PushToken(self::TOKEN_A), 1, 'first'),
        ]);
        $second = new ReceiptResult([
            new ReceiptEntry('r1', ReceiptState::Returned, new PushReceipt('r1', 'ok'), new PushToken(self::TOKEN_B), 2, 'second'),
        ]);

        $entry = $first->merge($second)->entry('r1');

        self::assertInstanceOf(ReceiptEntry::class, $entry);
        self::assertSame(self::TOKEN_A, $entry->token?->value);
        self::assertSame(1, $entry->notificationIndex);
        self::assertSame('first', $entry->reference);
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function impossibleDates(): iterable
    {
        yield 'day 32' => ['Sun, 32 Jan 2124 00:00:00 GMT'];
        yield 'month 13' => ['Sun, 01 Xxx 2124 00:00:00 GMT'];
        yield 'hour 25' => ['Sun, 01 Jan 2124 25:00:00 GMT'];
        yield 'minute 61' => ['Sun, 01 Jan 2124 00:61:00 GMT'];
        yield '31 February' => ['Thu, 31 Feb 2124 00:00:00 GMT'];
    }

    #[DataProvider('impossibleDates')]
    public function testAnImpossibleDateIsNotARetryAfterValue(string $value): void
    {
        self::assertNull(RetryAfter::parse($value, 1_700_000_000_000));
    }

    public function testAValidDateStillParses(): void
    {
        $at = gmdate('D, d M Y H:i:s \G\M\T', 1_700_000_000 + 120);

        self::assertSame(120_000, RetryAfter::parse($at, 1_700_000_000_000));
    }

    /**
     * A repeated receipt ID keeps the correlation of every notification that
     * asked for it, not only of the first one.
     */
    public function testARepeatedIdKeepsEveryReference(): void
    {
        $http = (new FakeHttpClient())->queue(['data' => ['r1' => ['status' => 'ok']]]);

        $result = $this->expo($http)->receipts([
            new ReceiptReference('r1', new PushToken(self::TOKEN_A), 4, 'order-4'),
            new ReceiptReference('r1', new PushToken(self::TOKEN_A), 9, 'order-9'),
        ]);

        $entry = $result->entry('r1');

        self::assertInstanceOf(ReceiptEntry::class, $entry);
        self::assertSame(['ids' => ['r1']], $http->payload());
        self::assertSame(1, $result->count());

        // The first reference sits on the entry, and the second one stays beside it.
        self::assertSame(4, $entry->notificationIndex);
        self::assertSame('order-4', $entry->reference);
        self::assertCount(1, $entry->otherReferences);
        self::assertSame(9, $entry->otherReferences[0]->notificationIndex);
        self::assertSame('order-9', $entry->otherReferences[0]->reference);

        self::assertCount(2, $entry->references());
        self::assertSame([4, 9], $entry->notificationIndexes());
    }

    public function testASingleReferenceKeepsNoExtraList(): void
    {
        $http = (new FakeHttpClient())->queue(['data' => ['r1' => ['status' => 'ok']]]);

        $result = $this->expo($http)->receipts([new ReceiptReference('r1', null, 2, 'order-2')]);
        $entry = $result->entry('r1');

        self::assertInstanceOf(ReceiptEntry::class, $entry);
        self::assertSame([], $entry->otherReferences);
        self::assertCount(1, $entry->references());
        self::assertSame([2], $entry->notificationIndexes());
    }

    public function testEveryReferenceOfARepeatedIdSurvivesStorage(): void
    {
        $http = (new FakeHttpClient())->queue(['data' => ['r1' => ['status' => 'ok']]]);

        $result = $this->expo($http)->receipts([
            new ReceiptReference('r1', new PushToken(self::TOKEN_A), 4, 'order-4'),
            new ReceiptReference('r1', new PushToken(self::TOKEN_A), 9, 'order-9'),
        ]);

        $json = json_encode($result->toStorageArray(), JSON_THROW_ON_ERROR);
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        self::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        $entry = ReceiptResult::fromStorageArray($decoded)->entry('r1');

        self::assertInstanceOf(ReceiptEntry::class, $entry);
        self::assertSame([4, 9], $entry->notificationIndexes());
        self::assertSame('order-9', $entry->otherReferences[0]->reference);
    }

    public function testAMergeKeepsTheReferencesOfBothLookups(): void
    {
        $first = new ReceiptResult([
            new ReceiptEntry('r1', ReceiptState::Missing, null, null, 1, 'first'),
        ]);
        $second = new ReceiptResult([
            new ReceiptEntry('r1', ReceiptState::Returned, new PushReceipt('r1', 'ok'), null, 2, 'second'),
        ]);

        $entry = $first->merge($second)->entry('r1');

        self::assertInstanceOf(ReceiptEntry::class, $entry);
        self::assertSame(ReceiptState::Returned, $entry->state);
        self::assertSame([1, 2], $entry->notificationIndexes());
    }

    public function testAMergeNeverRepeatsTheSameReference(): void
    {
        $entry = new ReceiptEntry('r1', ReceiptState::Missing, null, null, 1, 'first');
        $merged = (new ReceiptResult([$entry]))->merge(new ReceiptResult([$entry]))->entry('r1');

        self::assertInstanceOf(ReceiptEntry::class, $merged);
        self::assertSame([], $merged->otherReferences);
        self::assertSame([1], $merged->notificationIndexes());
    }

    /**
     * A returned entry without a receipt would report a complete lookup and give
     * nothing back.
     */
    public function testAStoredReturnedEntryWithoutAReceiptIsRejected(): void
    {
        $stored = StorageEnvelope::wrap(ReceiptEntry::STORAGE_TYPE, [
            'id' => 'r1',
            'state' => 'returned',
        ]);

        $this->expectException(InvalidStorageException::class);
        $this->expectExceptionMessage('no receipt');

        ReceiptEntry::fromStorageArray($stored);
    }

    public function testAStoredReturnedEntryWithABrokenReceiptIsRejected(): void
    {
        $stored = StorageEnvelope::wrap(ReceiptEntry::STORAGE_TYPE, [
            'id' => 'r1',
            'state' => 'returned',
            'receipt' => 'not an array',
        ]);

        $this->expectException(InvalidStorageException::class);
        $this->expectExceptionMessage('no receipt');

        ReceiptEntry::fromStorageArray($stored);
    }

    public function testAStoredMissingEntryWithAReceiptIsRejected(): void
    {
        $stored = StorageEnvelope::wrap(ReceiptEntry::STORAGE_TYPE, [
            'id' => 'r1',
            'state' => 'missing',
            'receipt' => (new PushReceipt('r1', 'ok'))->toStorageArray(),
        ]);

        $this->expectException(InvalidStorageException::class);
        $this->expectExceptionMessage('Only a returned entry');

        ReceiptEntry::fromStorageArray($stored);
    }

    public function testAValidStoredEntryStillReads(): void
    {
        $stored = StorageEnvelope::wrap(ReceiptEntry::STORAGE_TYPE, [
            'id' => 'r1',
            'state' => 'returned',
            'receipt' => (new PushReceipt('r1', 'ok'))->toStorageArray(),
        ]);

        $entry = ReceiptEntry::fromStorageArray($stored);

        self::assertTrue($entry->isReturned());
        self::assertSame('r1', $entry->receipt?->id);
    }

    public function testTheOfflineExampleTransportReadsAGzipBody(): void
    {
        require_once dirname(__DIR__, 2) . '/examples/bootstrap.php';

        $transport = new \OfflineTransport();

        $expo = new Expo(
            httpClient: $transport,
            clock: $this->clock,
            sleeper: $this->sleeper,
        );

        // 250 devices make a body far above the 1024 byte compression threshold.
        $result = $expo->send(\Expo\Push\PushMessage::to(self::tokens(250))->title('Broadcast'));

        self::assertSame('gzip', $transport->requests[0]->headers['content-encoding'] ?? null);
        self::assertCount(250, $result->accepted());
        self::assertSame([], $result->requestFailures());
    }
}
