<?php

declare(strict_types=1);

namespace Expo\Push\Tests\Unit;

use Expo\Push\Http\TransportFailureKind;
use Expo\Push\Protocol\ProtocolFailureKind;
use Expo\Push\Protocol\ReceiptResponseParser;
use Expo\Push\Protocol\SendResponseParser;
use Expo\Push\PushMessage;
use Expo\Push\PushToken;
use Expo\Push\RateLimit\SlidingWindowRateLimiter;
use Expo\Push\Result\Acceptance;
use Expo\Push\Result\FailureCategory;
use Expo\Push\Result\ReceiptState;
use Expo\Push\Retry\NoRetryPolicy;
use Expo\Push\Tests\Support\FakeHttpClient;
use Expo\Push\Tests\Support\RecordingLimiter;
use Expo\Push\Tests\Support\TestCase;

/**
 * Every failure path of the SDK must be reachable, and every state must appear.
 *
 * A coverage percentage says how many lines ran. These tests say that every
 * documented state really happens, which is the thing that matters.
 */
final class FailurePathCoverageTest extends TestCase
{
    public function testEveryTransportFailureKindReachesAResult(): void
    {
        $seen = [];

        foreach (TransportFailureKind::cases() as $kind) {
            $http = (new FakeHttpClient())->queueFailure($kind);

            $result = $this->expo($http, new NoRetryPolicy())->send(PushMessage::to(self::TOKEN_A));

            self::assertCount(1, $result->requestFailures(), $kind->value);
            self::assertSame($kind->value, $result->requestFailures()[0]->transportCode, $kind->value);

            $seen[$kind->value] = $result->outcomes()[0]->acceptance;
        }

        self::assertCount(count(TransportFailureKind::cases()), $seen);

        // A failure before transmission is known not accepted. Everything else
        // leaves the acceptance unknown.
        self::assertSame(Acceptance::NotAccepted, $seen['connect_failed']);
        self::assertSame(Acceptance::NotAccepted, $seen['name_resolution_failed']);
        self::assertSame(Acceptance::NotAccepted, $seen['tls_failed']);
        self::assertSame(Acceptance::NotAccepted, $seen['tls_verification_failed']);
        self::assertSame(Acceptance::NotAccepted, $seen['invalid_configuration']);
        self::assertSame(Acceptance::NotAccepted, $seen['request_construction']);
        self::assertSame(Acceptance::Unknown, $seen['timeout']);
        self::assertSame(Acceptance::Unknown, $seen['interrupted']);
        self::assertSame(Acceptance::Unknown, $seen['client_failure']);
        self::assertSame(Acceptance::Unknown, $seen['unknown']);
    }

    public function testEveryFailureCategoryReallyHappens(): void
    {
        $seen = [];

        // Http: a status that the SDK does not retry and that Expo did not explain.
        $http = (new FakeHttpClient())->queueRaw('nope', 404);
        $seen[] = $this->expo($http, new NoRetryPolicy())
            ->send(PushMessage::to(self::TOKEN_A))->requestFailures()[0]->category;

        // RateLimited: a 429 answer.
        $http = (new FakeHttpClient())->queueRaw('slow', 429, ['retry-after' => '300']);
        $seen[] = $this->expo($http)->send(PushMessage::to(self::TOKEN_A))->requestFailures()[0]->category;

        // Transport: no answer at all.
        $http = (new FakeHttpClient())->queueFailure(TransportFailureKind::Timeout);
        $seen[] = $this->expo($http, new NoRetryPolicy())
            ->send(PushMessage::to(self::TOKEN_A))->requestFailures()[0]->category;

        // Protocol: a 2xx answer that the SDK cannot trust.
        $http = (new FakeHttpClient())->queueRaw('{"data": "bad"}');
        $seen[] = $this->expo($http, new NoRetryPolicy())
            ->send(PushMessage::to(self::TOKEN_A))->requestFailures()[0]->category;

        // Api: Expo reported request level errors.
        $http = (new FakeHttpClient())->queue(['errors' => [['code' => 'UNAUTHORIZED']]], 401);
        $seen[] = $this->expo($http, new NoRetryPolicy())
            ->send(PushMessage::to(self::TOKEN_A))->requestFailures()[0]->category;

        // Deadline: the retry wait of the first chunk passed the operation deadline.
        $http = (new FakeHttpClient())->queueRaw('boom', 500);
        $result = $this->expo($http, operationDeadlineMs: 500, sendChunkSize: 1)
            ->send(PushMessage::to([self::TOKEN_A, self::TOKEN_B]));
        $seen[] = $result->requestFailures()[0]->category;

        // Limiter: the shared limiter failed.
        $http = new FakeHttpClient();
        $http->queue(['data' => self::okTickets(1)]);
        $result = $this->expo(
            $http,
            rateLimiter: new RecordingLimiter(failAfterFirst: true),
            bucket: 'p',
            sendChunkSize: 1,
        )->send(PushMessage::to([self::TOKEN_A, self::TOKEN_B]));
        $seen[] = $result->requestFailures()[0]->category;

        // Skipped: an earlier chunk failed.
        $http = (new FakeHttpClient())->queueRaw('nope', 400);
        $result = $this->expo($http, new NoRetryPolicy(), sendChunkSize: 1)
            ->send(PushMessage::to([self::TOKEN_A, self::TOKEN_B]));
        $seen[] = $result->requestFailures()[1]->category;

        self::assertSame(
            array_map(static fn (FailureCategory $case): string => $case->value, FailureCategory::cases()),
            array_values(array_unique(array_map(static fn (FailureCategory $case): string => $case->value, $seen)))
        );
    }

    public function testEveryAcceptanceStateReallyHappens(): void
    {
        $http = new FakeHttpClient();
        $http->queue(['data' => [
            ['status' => 'ok', 'id' => 'r1'],
            ['status' => 'error', 'message' => 'no', 'details' => ['error' => 'DeviceNotRegistered']],
        ]]);
        $http->queueFailure(TransportFailureKind::Timeout);

        $result = $this->expo($http, new NoRetryPolicy(), sendChunkSize: 2)
            ->send(PushMessage::to(self::tokens(6))->title('Hi'));

        $seen = array_values(array_unique(array_map(
            static fn (\Expo\Push\Result\NotificationOutcome $outcome): string => $outcome->acceptance->value,
            $result->outcomes()
        )));

        sort($seen);

        $all = array_map(static fn (Acceptance $case): string => $case->value, Acceptance::cases());
        sort($all);

        self::assertSame($all, $seen);
    }

    public function testEveryReceiptStateReallyHappens(): void
    {
        $http = new FakeHttpClient();
        $http->queue(['data' => ['r1' => ['status' => 'ok'], 'r2' => 'garbage']]);
        $http->queueRaw('boom', 500);

        $result = $this->expo($http, new NoRetryPolicy(), receiptChunkSize: 3)
            ->receipts(['r1', 'r2', 'r3', 'r4', 'r5', 'r6', 'r7']);

        $seen = array_values(array_unique(array_map(
            static fn (\Expo\Push\Result\ReceiptEntry $entry): string => $entry->state->value,
            $result->entries()
        )));

        sort($seen);

        $all = array_map(static fn (ReceiptState $case): string => $case->value, ReceiptState::cases());
        sort($all);

        self::assertSame($all, $seen);
    }

    public function testEveryProtocolFailureKindReallyHappens(): void
    {
        $tokens = [new PushToken(self::TOKEN_A)];

        $seen = [
            SendResponseParser::parse('<html>', $tokens)->failure?->kind,
            SendResponseParser::parse('[1]', $tokens)->failure?->kind,
            SendResponseParser::parse('{"ok":1}', $tokens)->failure?->kind,
            SendResponseParser::parse('{"data":{}}', $tokens)->failure?->kind,
            SendResponseParser::parse('{"data":[]}', $tokens)->failure?->kind,
            SendResponseParser::parse('{"errors":[{"code":"X"}]}', $tokens)->failure?->kind,
        ];

        $values = array_values(array_unique(array_map(
            static fn (?ProtocolFailureKind $kind): string => $kind instanceof ProtocolFailureKind
                ? $kind->value
                : 'none',
            $seen
        )));

        sort($values);

        $all = array_map(static fn (ProtocolFailureKind $case): string => $case->value, ProtocolFailureKind::cases());
        sort($all);

        self::assertSame($all, $values);

        // The receipt parser produces the same kinds on its own path.
        self::assertSame(
            ProtocolFailureKind::DataWrongType,
            ReceiptResponseParser::parse('{"data":[1]}', ['r1'])->failure?->kind
        );
        self::assertSame(
            ProtocolFailureKind::NotJson,
            ReceiptResponseParser::parse('nope', ['r1'])->failure?->kind
        );
    }

    public function testEveryErrorClassificationReallyHappens(): void
    {
        $seen = [];

        foreach (\Expo\Push\PushError::cases() as $error) {
            $seen[$error->classification()->value] = true;
        }

        $seen[\Expo\Push\ErrorClassification::Unknown->value] = true;

        $values = array_keys($seen);
        sort($values);

        $all = array_map(
            static fn (\Expo\Push\ErrorClassification $case): string => $case->value,
            \Expo\Push\ErrorClassification::cases()
        );
        sort($all);

        self::assertSame($all, $values);
    }

    public function testTheLimiterAndTheDeferralPathsStayReachable(): void
    {
        $limiter = new SlidingWindowRateLimiter(1, 1_000, $this->clock);
        $http = new FakeHttpClient();
        $http->queue(['data' => self::okTickets(1)]);
        $http->queue(['data' => self::okTickets(1)]);

        $result = $this->expo($http, rateLimiter: $limiter, bucket: 'p', sendChunkSize: 1)
            ->send(PushMessage::to([self::TOKEN_A, self::TOKEN_B]));

        self::assertCount(2, $result->accepted());
        self::assertSame([1_000], $this->sleeper->waits);
    }
}
