<?php

declare(strict_types=1);

namespace Expo\Push\Tests\Unit;

use Expo\Push\Http\TransportFailureKind;
use Expo\Push\PushMessage;
use Expo\Push\Result\Acceptance;
use Expo\Push\Result\FailureCategory;
use Expo\Push\Result\NotAcceptedReason;
use Expo\Push\Retry\ConservativeSendPolicy;
use Expo\Push\Retry\NoRetryPolicy;
use Expo\Push\Tests\Support\FakeHttpClient;
use Expo\Push\Tests\Support\TestCase;

/**
 * Acceptance stays honest when an attempt is ambiguous.
 */
final class AmbiguityTest extends TestCase
{
    public function testATimeoutThenSuccessIsAcceptedAndKeepsTheDuplicateRisk(): void
    {
        $http = new FakeHttpClient();
        $http->queueFailure(TransportFailureKind::Timeout);
        $http->queue(['data' => [['status' => 'ok', 'id' => 'ticket-1']]]);

        $result = $this->expo($http)->send(PushMessage::to(self::TOKEN_A));

        $outcome = $result->outcomes()[0];

        self::assertSame(Acceptance::Accepted, $outcome->acceptance);
        self::assertSame('ticket-1', $outcome->receiptId());
        self::assertTrue($outcome->duplicateRisk);
        self::assertCount(1, $result->duplicateRisk());
        self::assertStringContainsString('twice', (string) $outcome->detail);
    }

    public function testATimeoutThenRejectionStaysUnknown(): void
    {
        $http = new FakeHttpClient();
        $http->queueFailure(TransportFailureKind::Timeout);
        $http->queue(['data' => [
            ['status' => 'error', 'message' => 'gone', 'details' => ['error' => 'DeviceNotRegistered']],
        ]]);

        $result = $this->expo($http)->send(PushMessage::to(self::TOKEN_A));

        $outcome = $result->outcomes()[0];

        self::assertSame(Acceptance::Unknown, $outcome->acceptance);
        self::assertNull($outcome->reason);
        self::assertTrue($outcome->duplicateRisk);
        // The ticket stays available, so the application can read the error code.
        self::assertSame('DeviceNotRegistered', $outcome->ticket?->errorCode);
        // An unknown acceptance is not evidence that the token is dead.
        self::assertSame([self::TOKEN_A], self::values($result->unregisteredTokens()));
    }

    public function testAFailureBeforeTransmissionIsNotAcceptedAndNotUnknown(): void
    {
        $http = new FakeHttpClient();
        $http->queueFailure(TransportFailureKind::ConnectFailed);
        $http->queueFailure(TransportFailureKind::ConnectFailed);
        $http->queueFailure(TransportFailureKind::ConnectFailed);

        $result = $this->expo($http)->send(PushMessage::to(self::TOKEN_A));

        $outcome = $result->outcomes()[0];

        self::assertSame(Acceptance::NotAccepted, $outcome->acceptance);
        self::assertSame(NotAcceptedReason::NotTransmitted, $outcome->reason);
        self::assertFalse($outcome->duplicateRisk);
        self::assertSame(3, $result->requestFailures()[0]->attemptCount());
        self::assertSame(FailureCategory::Transport, $result->requestFailures()[0]->category);
    }

    public function testAFailureBeforeTransmissionIsStillAnAttempt(): void
    {
        $http = (new FakeHttpClient())->queueFailure(TransportFailureKind::NameResolutionFailed);

        $result = $this->expo($http, new NoRetryPolicy())->send(PushMessage::to(self::TOKEN_A));

        self::assertCount(0, $result->notAttempted());
        self::assertCount(1, $result->notAccepted());
        self::assertTrue($result->outcomes()[0]->wasAttempted());
    }

    public function testATimeoutWithoutARetryStaysUnknown(): void
    {
        $http = (new FakeHttpClient())->queueFailure(TransportFailureKind::Timeout);

        $result = $this->expo($http, new NoRetryPolicy())->send(PushMessage::to(self::TOKEN_A));

        self::assertSame(Acceptance::Unknown, $result->outcomes()[0]->acceptance);
        self::assertTrue($result->outcomes()[0]->duplicateRisk);
        self::assertSame(1, $http->requestCount());
    }

    public function testTheConservativePolicyNeverRepeatsATimeout(): void
    {
        $http = new FakeHttpClient();
        $http->queueFailure(TransportFailureKind::Timeout);
        $http->queue(['data' => [['status' => 'ok', 'id' => 'ticket-1']]]);

        $result = $this->expo($http, new ConservativeSendPolicy())->send(PushMessage::to(self::TOKEN_A));

        self::assertSame(1, $http->requestCount());
        self::assertSame(Acceptance::Unknown, $result->outcomes()[0]->acceptance);
        self::assertStringContainsString('cannot prove', $result->requestFailures()[0]->message);
    }

    public function testTheConservativePolicyRepeatsAConnectionFailure(): void
    {
        $http = new FakeHttpClient();
        $http->queueFailure(TransportFailureKind::ConnectFailed);
        $http->queue(['data' => [['status' => 'ok', 'id' => 'ticket-1']]]);

        $result = $this->expo($http, new ConservativeSendPolicy())->send(PushMessage::to(self::TOKEN_A));

        self::assertSame(2, $http->requestCount());
        self::assertSame(Acceptance::Accepted, $result->outcomes()[0]->acceptance);
        self::assertFalse($result->outcomes()[0]->duplicateRisk);
    }

    public function testTheConservativePolicyRepeatsAnExplicitRateLimit(): void
    {
        $http = new FakeHttpClient();
        $http->queue(['errors' => [['code' => 'TOO_MANY_REQUESTS', 'message' => 'slow down']]], 429);
        $http->queue(['data' => [['status' => 'ok', 'id' => 'ticket-1']]]);

        $result = $this->expo($http, new ConservativeSendPolicy())->send(PushMessage::to(self::TOKEN_A));

        self::assertSame(2, $http->requestCount());
        self::assertSame(Acceptance::Accepted, $result->outcomes()[0]->acceptance);
        self::assertFalse($result->outcomes()[0]->duplicateRisk);
    }

    public function testAProtocolFailureIsNeverRepeatedAndLeavesTheAcceptanceUnknown(): void
    {
        $http = new FakeHttpClient();
        $http->queueRaw('{"data": "not a list"}', 200);
        $http->queue(['data' => [['status' => 'ok', 'id' => 'ticket-1']]]);

        $result = $this->expo($http)->send(PushMessage::to(self::TOKEN_A));

        self::assertSame(1, $http->requestCount());
        self::assertSame(Acceptance::Unknown, $result->outcomes()[0]->acceptance);
        self::assertSame(FailureCategory::Protocol, $result->requestFailures()[0]->category);
        self::assertTrue($result->outcomes()[0]->duplicateRisk);
    }

    public function testAnInterruptedTransferIsAmbiguousAndAProtocolFailureIsNot(): void
    {
        $interrupted = (new FakeHttpClient())->queueFailure(TransportFailureKind::Interrupted);
        $interruptedResult = $this->expo($interrupted, new NoRetryPolicy())->send(PushMessage::to(self::TOKEN_A));

        $broken = (new FakeHttpClient())->queueRaw('{}', 200);
        $brokenResult = $this->expo($broken, new NoRetryPolicy())->send(PushMessage::to(self::TOKEN_A));

        self::assertSame(FailureCategory::Transport, $interruptedResult->requestFailures()[0]->category);
        self::assertSame(FailureCategory::Protocol, $brokenResult->requestFailures()[0]->category);
        self::assertSame(Acceptance::Unknown, $interruptedResult->outcomes()[0]->acceptance);
        self::assertSame(Acceptance::Unknown, $brokenResult->outcomes()[0]->acceptance);
    }
}
