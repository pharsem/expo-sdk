<?php

declare(strict_types=1);

namespace Expo\Push\Tests\Unit;

use Expo\Push\Protocol\ProtocolFailureKind;
use Expo\Push\Protocol\ReceiptResponseParser;
use Expo\Push\Protocol\SendResponseParser;
use Expo\Push\PushMessage;
use Expo\Push\PushToken;
use Expo\Push\Result\Acceptance;
use Expo\Push\Result\FailureCategory;
use Expo\Push\Result\ReceiptState;
use Expo\Push\Retry\NoRetryPolicy;
use Expo\Push\Tests\Support\FakeHttpClient;
use Expo\Push\Tests\Support\TestCase;

final class ProtocolTest extends TestCase
{
    /**
     * @return list<PushToken>
     */
    private static function twoTokens(): array
    {
        return [new PushToken(self::TOKEN_A), new PushToken(self::TOKEN_B)];
    }

    public function testABodyThatIsNotJsonIsAProtocolFailure(): void
    {
        $response = SendResponseParser::parse('<html>hi</html>', self::twoTokens());

        self::assertFalse($response->isUsable());
        self::assertSame(ProtocolFailureKind::NotJson, $response->failure?->kind);
    }

    public function testAJsonArrayEnvelopeIsAProtocolFailure(): void
    {
        $response = SendResponseParser::parse('[1, 2, 3]', self::twoTokens());

        self::assertSame(ProtocolFailureKind::EnvelopeNotObject, $response->failure?->kind);
    }

    public function testAMissingDataFieldIsAProtocolFailure(): void
    {
        $response = SendResponseParser::parse('{"ok": true}', self::twoTokens());

        self::assertSame(ProtocolFailureKind::DataMissing, $response->failure?->kind);
    }

    public function testADataObjectInASendAnswerIsAProtocolFailure(): void
    {
        $response = SendResponseParser::parse('{"data": {"a": 1}}', self::twoTokens());

        self::assertSame(ProtocolFailureKind::DataWrongType, $response->failure?->kind);
    }

    public function testErrorsWithoutDataBecomeAnApiFailure(): void
    {
        $body = '{"errors": [{"code": "PUSH_TOO_MANY_NOTIFICATIONS", "message": "too many"}]}';

        $response = SendResponseParser::parse($body, self::twoTokens());

        self::assertSame(ProtocolFailureKind::ErrorsWithoutData, $response->failure?->kind);
        self::assertCount(1, $response->apiErrors);
        self::assertSame('PUSH_TOO_MANY_NOTIFICATIONS', $response->apiErrors[0]->code);
    }

    /**
     * A wrong ticket count makes every position untrustworthy. The parser never
     * guesses that the first N entries belong to the first N devices.
     */
    public function testAWrongTicketCountIsAProtocolFailureAndKeepsTheIds(): void
    {
        $body = '{"data": [{"status": "ok", "id": "t1"}]}';

        $response = SendResponseParser::parse($body, self::twoTokens());

        self::assertSame(ProtocolFailureKind::TicketCountMismatch, $response->failure?->kind);
        self::assertSame(['t1'], $response->uncorrelatedIds);
        self::assertSame([], $response->tickets);
    }

    public function testTheSendKeepsTheValidEntriesWhenOneEntryIsMalformed(): void
    {
        $body = '{"data": ["garbage", {"status": "ok", "id": "t2"}]}';

        $response = SendResponseParser::parse($body, self::twoTokens());

        self::assertTrue($response->isUsable());
        self::assertNull($response->tickets[0]);
        self::assertSame('t2', $response->tickets[1]?->id);
        self::assertSame(self::TOKEN_B, $response->tickets[1]->token?->value);
        self::assertStringContainsString('malformed', $response->warnings[0]);
    }

    public function testAnUnknownStatusIsNotARejection(): void
    {
        $body = '{"data": [{"status": "maybe"}, {"status": "ok", "id": "t2"}]}';

        $response = SendResponseParser::parse($body, self::twoTokens());

        self::assertNull($response->tickets[0]);
        self::assertSame('t2', $response->tickets[1]?->id);
    }

    public function testASuccessfulTicketWithoutAnIdIsMalformed(): void
    {
        $body = '{"data": [{"status": "ok"}, {"status": "ok", "id": "t2"}]}';

        $response = SendResponseParser::parse($body, self::twoTokens());

        self::assertNull($response->tickets[0]);
        self::assertSame('t2', $response->tickets[1]?->id);
    }

    public function testAnUnknownErrorCodeStaysAvailable(): void
    {
        $body = '{"data": [{"status": "error", "message": "boom", "details": {"error": "SomethingNew", "x": 1}}, '
            . '{"status": "ok", "id": "t2"}]}';

        $response = SendResponseParser::parse($body, self::twoTokens());

        self::assertSame('SomethingNew', $response->tickets[0]?->errorCode);
        self::assertNull($response->tickets[0]->error);
        self::assertSame(\Expo\Push\ErrorClassification::Unknown, $response->tickets[0]->classification());
        self::assertSame(['error' => 'SomethingNew', 'x' => 1], $response->tickets[0]->details);
    }

    public function testAnEnvelopeWithBothTicketsAndErrorsIsFlaggedAndUsed(): void
    {
        $body = '{"data": [{"status": "ok", "id": "t1"}, {"status": "ok", "id": "t2"}], '
            . '"errors": [{"code": "SOMETHING", "message": "odd"}]}';

        $response = SendResponseParser::parse($body, self::twoTokens());

        self::assertTrue($response->isUsable());
        self::assertSame('t1', $response->tickets[0]?->id);
        self::assertStringContainsString('both tickets and request level errors', implode(' ', $response->warnings));
    }

    public function testAMalformedEntryLeavesTheAcceptanceUnknown(): void
    {
        $http = (new FakeHttpClient())->queueRaw('{"data": ["garbage", {"status": "ok", "id": "t2"}]}');

        $result = $this->expo($http)->send(PushMessage::to([self::TOKEN_A, self::TOKEN_B]));

        self::assertSame(Acceptance::Unknown, $result->outcomes()[0]->acceptance);
        self::assertSame(Acceptance::Accepted, $result->outcomes()[1]->acceptance);
        self::assertCount(0, $result->requestFailures());
        self::assertStringContainsString('malformed', (string) $result->outcomes()[0]->detail);
        self::assertStringContainsString('malformed', implode(' ', $result->warnings()));
    }

    public function testAWrongCountLeavesEveryPositionUnknownAndKeepsTheIds(): void
    {
        $http = (new FakeHttpClient())->queueRaw('{"data": [{"status": "ok", "id": "t1"}]}');

        $result = $this->expo($http, new NoRetryPolicy())->send(PushMessage::to([self::TOKEN_A, self::TOKEN_B]));

        self::assertCount(2, $result->unknown());
        self::assertSame(['t1'], $result->uncorrelatedReceiptIds());
        self::assertCount(0, $result->tickets());
        self::assertSame(FailureCategory::Protocol, $result->requestFailures()[0]->category);
    }

    public function testTheReceiptParserRejectsANonObjectData(): void
    {
        $response = ReceiptResponseParser::parse('{"data": [1, 2]}', ['a']);

        self::assertSame(ProtocolFailureKind::DataWrongType, $response->failure?->kind);
    }

    public function testTheReceiptParserAcceptsAnEmptyArrayAsAnEmptyMap(): void
    {
        $response = ReceiptResponseParser::parse('{"data": []}', ['a']);

        self::assertTrue($response->isUsable());
        self::assertSame([], $response->receipts);
    }

    public function testTheReceiptParserCorrelatesByIdAndNotByOrder(): void
    {
        $body = '{"data": {"b": {"status": "ok"}, "a": {"status": "error", "message": "no", '
            . '"details": {"error": "DeviceNotRegistered"}}}}';

        $response = ReceiptResponseParser::parse($body, ['a', 'b']);

        self::assertTrue($response->receipts['b']->isOk());
        self::assertTrue($response->receipts['a']->isError());
        self::assertSame('a', $response->receipts['a']->id);
    }

    public function testTheReceiptParserKeepsTheValidEntriesAroundAMalformedOne(): void
    {
        $body = '{"data": {"a": "garbage", "b": {"status": "ok"}}}';

        $response = ReceiptResponseParser::parse($body, ['a', 'b']);

        self::assertSame(['a'], $response->malformedIds);
        self::assertArrayHasKey('b', $response->receipts);
    }

    public function testAnUnexpectedIdNeverTakesAnotherToken(): void
    {
        $body = '{"data": {"a": {"status": "ok"}, "zz": {"status": "ok"}}}';
        $tokens = ['a' => new PushToken(self::TOKEN_A)];

        $response = ReceiptResponseParser::parse($body, ['a'], $tokens);

        self::assertSame(['zz'], $response->unexpectedIds);
        self::assertSame(self::TOKEN_A, $response->receipts['a']->token?->value);
        self::assertArrayNotHasKey('zz', $response->receipts);
    }

    public function testAnInvalidReceiptAnswerIsAFailedLookupAndNotAMissingOne(): void
    {
        $http = (new FakeHttpClient())->queueRaw('<html>nope</html>', 200);

        $result = $this->expo($http, new NoRetryPolicy())->receipts(['a', 'b']);

        self::assertSame(['a', 'b'], $result->failedIds());
        self::assertSame([], $result->missingIds());
        self::assertSame(FailureCategory::Protocol, $result->requestFailures()[0]->category);
        self::assertSame(ReceiptState::LookupFailed, $result->state('a'));
    }

    public function testTheProtocolSnippetHoldsNoToken(): void
    {
        $body = '{"data": "bad", "token": "' . self::TOKEN_A . '"}';

        $response = SendResponseParser::parse($body, self::twoTokens());

        self::assertStringNotContainsString('ExponentPushToken', (string) $response->failure?->snippet);
        self::assertStringContainsString('[token]', (string) $response->failure?->snippet);
    }
}
