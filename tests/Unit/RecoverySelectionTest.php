<?php

declare(strict_types=1);

namespace Expo\Push\Tests\Unit;

use Expo\Push\Http\TransportFailureKind;
use Expo\Push\PushMessage;
use Expo\Push\Result\Acceptance;
use Expo\Push\Result\FailureCategory;
use Expo\Push\Result\NotAcceptedReason;
use Expo\Push\Result\RecoverableWork;
use Expo\Push\Result\RecoveryDisposition;
use Expo\Push\Retry\NoRetryPolicy;
use Expo\Push\Tests\Support\FakeHttpClient;
use Expo\Push\Tests\Support\TestCase;

/**
 * Recovery reads two things at once: what Expo accepted, and what is left to do.
 *
 * A notification that Expo is known not to have accepted still belongs in the
 * worklist when the failure behind it can pass later. The reviewed SDK selected
 * on acceptance alone, so a deferred 429 disappeared from `recoverable()`.
 */
final class RecoverySelectionTest extends TestCase
{
    /**
     * The scenario of the review: one notification, one 429, `Retry-After: 120`.
     */
    public function testADeferredRateLimitStaysRecoverableWork(): void
    {
        $http = (new FakeHttpClient())->queueRaw('slow down', 429, ['Retry-After' => '120']);

        $result = $this->expo($http)->send(
            PushMessage::to(self::TOKEN_A)->title('Hi')->reference('order-7')
        );

        $outcome = $result->outcomes()[0];
        $expected = $this->clock->nowUtcMillis() + 120_000;

        // The server refused the request, so Expo accepted nothing.
        self::assertSame(Acceptance::NotAccepted, $outcome->acceptance);
        self::assertSame(NotAcceptedReason::Rejected, $outcome->reason);
        self::assertFalse($outcome->duplicateRisk);

        // The failure is transient, and it names the moment.
        $failure = $result->requestFailures()[0];
        self::assertSame(FailureCategory::RateLimited, $failure->category);
        self::assertTrue($failure->retryable);
        self::assertTrue($failure->deferred);
        self::assertSame($expected, $failure->earliestRetryAtUtcMs);

        // So the notification stays open work, with its own identity.
        $work = $result->recoverable();

        self::assertFalse($work->isEmpty());
        self::assertSame(1, $work->count());
        self::assertSame([0], $work->indexes());
        self::assertSame(self::TOKEN_A, $work->outcomes[0]->token->value);
        self::assertSame(['order-7'], $work->references());
        self::assertSame(0, $work->outcomes[0]->recipientIndex);
        self::assertSame(RecoveryDisposition::Retryable, $work->outcomes[0]->recovery);
        self::assertSame($expected, $work->outcomes[0]->earliestRetryAtUtcMs);
        self::assertSame($expected, $work->earliestRetryAtUtcMs);
        self::assertCount(1, $work->retryable());
        self::assertSame([], $work->needsIntervention());
        self::assertSame([], $work->ambiguous());
        self::assertSame([], $work->notAttempted());

        // Eligible is not the same as due.
        self::assertSame([], $work->dueAt($this->clock->nowUtcMillis()));
        self::assertCount(1, $work->dueAt($expected));
    }

    /**
     * A connection that never opened transmitted nothing, so a resend is safe.
     */
    public function testAPreTransmissionFailureThatExhaustsTheRetriesStaysRecoverable(): void
    {
        $http = new FakeHttpClient();
        $http->queueFailure(TransportFailureKind::ConnectFailed);
        $http->queueFailure(TransportFailureKind::ConnectFailed);
        $http->queueFailure(TransportFailureKind::ConnectFailed);

        $result = $this->expo($http)->send(PushMessage::to(self::TOKEN_A)->title('Hi'));

        $outcome = $result->outcomes()[0];

        self::assertSame(3, $http->requestCount());
        self::assertSame(Acceptance::NotAccepted, $outcome->acceptance);
        self::assertSame(NotAcceptedReason::NotTransmitted, $outcome->reason);
        self::assertFalse($outcome->duplicateRisk);
        self::assertSame(RecoveryDisposition::Retryable, $outcome->recovery);
        self::assertSame(FailureCategory::Transport, $result->requestFailures()[0]->category);
        self::assertTrue($result->requestFailures()[0]->retryable);
        self::assertFalse($result->requestFailures()[0]->deferred);

        $work = $result->recoverable();

        self::assertSame(1, $work->count());
        self::assertCount(1, $work->retryable());
        // Nothing left this process, so a resend cannot duplicate anything.
        self::assertSame([], $work->ambiguous());
        // It is due now: no server asked the SDK to wait.
        self::assertCount(1, $work->dueAt($this->clock->nowUtcMillis()));
    }

    public function testAPermanentTokenRejectionIsNotRecoverableWork(): void
    {
        $http = (new FakeHttpClient())->queue(['data' => [
            [
                'status' => 'error',
                'message' => 'the device is not registered',
                'details' => ['error' => 'DeviceNotRegistered'],
            ],
        ]]);

        $result = $this->expo($http)->send(PushMessage::to(self::TOKEN_A)->title('Hi'));

        $outcome = $result->outcomes()[0];

        self::assertSame(Acceptance::NotAccepted, $outcome->acceptance);
        self::assertSame(NotAcceptedReason::Rejected, $outcome->reason);
        self::assertSame(RecoveryDisposition::None, $outcome->recovery);
        self::assertFalse($outcome->isOpen());
        self::assertTrue($result->recoverable()->isEmpty());
        self::assertSame([self::TOKEN_A], self::values($result->unregisteredTokens()));
    }

    /**
     * Expo refuses this one device for now. A later send of the same message
     * can work, so the notification stays open work.
     */
    public function testARateLimitedTicketStaysRecoverableWork(): void
    {
        $http = (new FakeHttpClient())->queue(['data' => [
            [
                'status' => 'error',
                'message' => 'too fast',
                'details' => ['error' => 'MessageRateExceeded'],
            ],
        ]]);

        $result = $this->expo($http)->send(PushMessage::to(self::TOKEN_A)->title('Hi'));
        $work = $result->recoverable();

        self::assertSame(Acceptance::NotAccepted, $result->outcomes()[0]->acceptance);
        self::assertSame(RecoveryDisposition::Retryable, $result->outcomes()[0]->recovery);
        self::assertSame(1, $work->count());
        self::assertCount(1, $work->retryable());
    }

    /**
     * An error code that the SDK does not know says nothing either way, so the
     * work stays open and asks the application to look.
     */
    public function testAnUnknownProviderErrorAsksForADecision(): void
    {
        $http = (new FakeHttpClient())->queue(['data' => [
            [
                'status' => 'error',
                'message' => 'something new',
                'details' => ['error' => 'SomethingBrandNew'],
            ],
        ]]);

        $result = $this->expo($http)->send(PushMessage::to(self::TOKEN_A)->title('Hi'));
        $work = $result->recoverable();

        self::assertSame(RecoveryDisposition::NeedsIntervention, $result->outcomes()[0]->recovery);
        self::assertCount(1, $work->needsIntervention());
        self::assertSame([], $work->retryable());
        self::assertSame([], $work->dueAt($this->clock->nowUtcMillis() + 3_600_000));
        self::assertSame('SomethingBrandNew', $result->outcomes()[0]->ticket?->errorCode);
    }

    public function testATimeoutFollowedBySuccessLeavesNoWorkAndKeepsTheRisk(): void
    {
        $http = new FakeHttpClient();
        $http->queueFailure(TransportFailureKind::Timeout);
        $http->queue(['data' => self::okTickets(1)]);

        $result = $this->expo($http)->send(PushMessage::to(self::TOKEN_A)->title('Hi'));

        $outcome = $result->outcomes()[0];

        self::assertSame(Acceptance::Accepted, $outcome->acceptance);
        self::assertTrue($outcome->duplicateRisk);
        self::assertSame('ticket-0', $outcome->receiptId());
        // An accepted notification is never automatic resend work, whatever the
        // earlier ambiguity did.
        self::assertSame(RecoveryDisposition::None, $outcome->recovery);
        self::assertTrue($result->recoverable()->isEmpty());
    }

    public function testATimeoutFollowedByARejectionStaysUnknownAndOpen(): void
    {
        $http = new FakeHttpClient();
        $http->queueFailure(TransportFailureKind::Timeout);
        $http->queue(['data' => [
            [
                'status' => 'error',
                'message' => 'the device is not registered',
                'details' => ['error' => 'DeviceNotRegistered'],
            ],
        ]]);

        $result = $this->expo($http)->send(PushMessage::to(self::TOKEN_A)->title('Hi'));

        $outcome = $result->outcomes()[0];

        // The last answer rejected it, and the first attempt may already have
        // been accepted. The SDK does not guess.
        self::assertSame(Acceptance::Unknown, $outcome->acceptance);
        self::assertTrue($outcome->duplicateRisk);
        self::assertSame('DeviceNotRegistered', $outcome->ticket?->errorCode);

        $work = $result->recoverable();

        self::assertSame(1, $work->count());
        self::assertCount(1, $work->ambiguous());
        // A repeat needs a decision: the code says no, and the history says
        // maybe.
        self::assertCount(1, $work->needsIntervention());
    }

    /**
     * Five chunks of one notification each, one for every state that matters.
     */
    public function testAMixedMultiChunkResultSortsEveryStateIntoTheRightPlace(): void
    {
        $http = new FakeHttpClient();
        // 0: accepted
        $http->queue(['data' => [['status' => 'ok', 'id' => 'ticket-0']]]);
        // 1: permanent rejection
        $http->queue(['data' => [
            ['status' => 'error', 'message' => 'gone', 'details' => ['error' => 'DeviceNotRegistered']],
        ]]);
        // 2: deferred 429
        $http->queueRaw('slow down', 429, ['Retry-After' => '120']);
        // 3 and 4 never go out: the 429 stops the operation.

        $result = $this->expo($http, new NoRetryPolicy(), continueAfterFailure: false, sendChunkSize: 1)
            ->send(PushMessage::to(self::tokens(5))->title('Hi'));

        self::assertSame(3, $http->requestCount());
        self::assertSame(
            [
                Acceptance::Accepted,
                Acceptance::NotAccepted,
                Acceptance::NotAccepted,
                Acceptance::NotAttempted,
                Acceptance::NotAttempted,
            ],
            array_map(
                static fn (\Expo\Push\Result\NotificationOutcome $o): Acceptance => $o->acceptance,
                $result->outcomes()
            )
        );

        $work = $result->recoverable();

        // The accepted one and the dead token stay out. The rate limit and the
        // two chunks that never started stay in.
        self::assertSame([2, 3, 4], $work->indexes());
        self::assertSame(
            ['total' => 3, 'notAttempted' => 2, 'ambiguous' => 0, 'retryable' => 3, 'needsIntervention' => 0],
            $work->summary()
        );

        // The retry guidance of the 429 reaches the chunks that it stopped.
        $expected = $this->clock->nowUtcMillis() + 120_000;

        foreach ($work->outcomes as $outcome) {
            self::assertSame($expected, $outcome->earliestRetryAtUtcMs, 'index ' . $outcome->index);
        }
    }

    /**
     * A transient failure of one chunk must not make the permanent rejection of
     * another chunk look retryable.
     */
    public function testARetryableChunkNeverMakesAnotherChunksRejectionRetryable(): void
    {
        $http = new FakeHttpClient();
        $http->queue(['data' => [
            ['status' => 'error', 'message' => 'gone', 'details' => ['error' => 'DeviceNotRegistered']],
        ]]);
        $http->queueRaw('slow down', 429, ['Retry-After' => '120']);

        $result = $this->expo($http, new NoRetryPolicy(), continueAfterFailure: true, sendChunkSize: 1)
            ->send(PushMessage::to(self::tokens(2))->title('Hi'));

        self::assertSame(RecoveryDisposition::None, $result->outcomes()[0]->recovery);
        self::assertSame(RecoveryDisposition::Retryable, $result->outcomes()[1]->recovery);
        self::assertSame([1], $result->recoverable()->indexes());
        self::assertNull($result->outcomes()[0]->earliestRetryAtUtcMs);
    }

    /**
     * Work that a credential failure stopped needs a fix, not a retry loop.
     */
    public function testWorkStoppedByACredentialFailureNeedsIntervention(): void
    {
        $http = new FakeHttpClient();
        $http->queueRaw('{"errors":[{"code":"UNAUTHORIZED","message":"bad token"}]}', 401);

        $result = $this->expo($http, sendChunkSize: 1)->send(PushMessage::to(self::tokens(2))->title('Hi'));

        self::assertSame(1, $http->requestCount());
        self::assertSame(Acceptance::NotAccepted, $result->outcomes()[0]->acceptance);
        self::assertSame(Acceptance::NotAttempted, $result->outcomes()[1]->acceptance);

        $work = $result->recoverable();

        self::assertSame([0, 1], $work->indexes());
        // Neither the refused chunk nor the chunk behind it may go out again
        // before somebody fixes the credentials.
        self::assertCount(2, $work->needsIntervention());
        self::assertSame([], $work->retryable());
        self::assertSame([], $work->dueAt($this->clock->nowUtcMillis() + 86_400_000));
    }

    /**
     * Two attempts, one notification, one entry in the worklist.
     */
    public function testSeveralAttemptsOnOneNotificationProduceOneEntry(): void
    {
        $http = new FakeHttpClient();
        $http->queueRaw('boom', 500);
        $http->queueRaw('boom', 500);
        $http->queueRaw('boom', 500);

        $result = $this->expo($http)->send(PushMessage::to(self::TOKEN_A)->title('Hi'));
        $work = $result->recoverable();

        self::assertSame(3, $http->requestCount());
        self::assertSame(1, $work->count());
        self::assertSame(0, $work->outcomes[0]->failureIndex);

        // One entry in the worklist, and all three attempts still on the
        // failure that it points at.
        self::assertSame(3, $result->requestFailures()[0]->attemptCount());
        self::assertSame([1, 2, 3], array_map(
            static fn (\Expo\Push\Result\AttemptRecord $attempt): int => $attempt->number,
            $result->requestFailures()[0]->attempts
        ));
    }

    /**
     * Two messages that share a device stay two notifications.
     */
    public function testTwoNotificationsThatShareATokenStayApart(): void
    {
        $http = new FakeHttpClient();
        $http->queueFailure(TransportFailureKind::ConnectFailed);
        $http->queueFailure(TransportFailureKind::ConnectFailed);
        $http->queueFailure(TransportFailureKind::ConnectFailed);

        $result = $this->expo($http)->send([
            PushMessage::to(self::TOKEN_A)->title('One')->reference('a'),
            PushMessage::to(self::TOKEN_A)->title('Two')->reference('b'),
        ]);

        $work = $result->recoverable();

        self::assertSame(2, $work->count());
        self::assertSame([0, 1], $work->indexes());
        self::assertSame(['a', 'b'], $work->references());
        // One device, two notifications.
        self::assertCount(1, $work->tokens());
    }

    public function testTheSelectionSurvivesAStorageRoundTrip(): void
    {
        $http = (new FakeHttpClient())->queueRaw('slow down', 429, ['Retry-After' => '120']);

        $work = $this->expo($http)->send(
            PushMessage::to(self::TOKEN_A)->title('Hi')->reference('order-7')
        )->recoverable();

        $json = json_encode($work->toStorageArray(), JSON_THROW_ON_ERROR);
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        self::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        $restored = RecoverableWork::fromStorageArray($decoded);

        self::assertSame(1, $restored->count());
        self::assertSame([0], $restored->indexes());
        self::assertSame(Acceptance::NotAccepted, $restored->outcomes[0]->acceptance);
        self::assertSame(RecoveryDisposition::Retryable, $restored->outcomes[0]->recovery);
        self::assertSame('order-7', $restored->outcomes[0]->reference);
        self::assertSame(
            $this->clock->nowUtcMillis() + 120_000,
            $restored->outcomes[0]->earliestRetryAtUtcMs
        );
        self::assertCount(1, $restored->retryable());
    }

    /**
     * An outcome from SDK 2.0.0 holds no recovery field. The reader takes the
     * careful value, and never turns open work into closed work.
     */
    public function testAStoredOutcomeWithoutARecoveryFieldStaysOpen(): void
    {
        $http = (new FakeHttpClient())->queueRaw('slow down', 429, ['Retry-After' => '120']);

        $stored = $this->expo($http)
            ->send(PushMessage::to(self::TOKEN_A)->title('Hi'))
            ->outcomes()[0]
            ->toStorageArray();

        self::assertIsArray($stored['data']);
        unset($stored['data']['recovery']);

        $restored = \Expo\Push\Result\NotificationOutcome::fromStorageArray($stored);

        self::assertTrue($restored->isOpen());
        self::assertSame(RecoveryDisposition::NeedsIntervention, $restored->recovery);
    }
}
