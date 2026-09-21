<?php

declare(strict_types=1);

namespace Expo\Push\Retry;

use Expo\Push\Http\TransportFailureKind;
use Expo\Push\Result\OperationType;

/**
 * The default policy. It works for delivery, not for exactly once.
 *
 * It retries:
 *
 * - HTTP 408, 429 and every 5xx status.
 * - A connection failure, a name resolution failure, a TLS handshake failure,
 *   a timeout and an interrupted transfer.
 *
 * It never retries:
 *
 * - Invalid input and invalid configuration.
 * - A certificate that does not verify.
 * - An ordinary 4xx status other than 408 and 429.
 * - A 2xx answer with a body that the SDK cannot trust. That is a protocol
 *   failure: the acceptance is unknown, and a repeat can double the send.
 * - An error ticket inside a successful request. Replaying the whole request
 *   would send the accepted notifications a second time.
 *
 * A timeout and an interrupted transfer are ambiguous: Expo can already hold the
 * notifications. A retry after one of them can produce a duplicate on the device.
 * The result marks every such notification with `duplicateRisk`. Use
 * `ConservativeSendPolicy` when a duplicate costs more than a lost notification.
 */
final readonly class DeliveryRetryPolicy implements RetryPolicy
{
    /** @var list<int> */
    private const array RETRYABLE_STATUSES = [408, 429];

    public function __construct(private RetrySettings $settings = new RetrySettings())
    {
    }

    #[\Override]
    public function settings(): RetrySettings
    {
        return $this->settings;
    }

    #[\Override]
    public function decide(OperationType $operation, int $attempt, AttemptOutcome $outcome): RetryDecision
    {
        if ($attempt >= $this->settings->maxAttempts) {
            return RetryDecision::stop(sprintf('the policy allows %d attempts', $this->settings->maxAttempts));
        }

        return match ($outcome->result) {
            AttemptResult::Success => RetryDecision::stop('the request succeeded'),
            AttemptResult::ProtocolFailure => RetryDecision::stop(
                'the answer was 2xx but not trustworthy, so a repeat can double the send'
            ),
            AttemptResult::HttpFailure => self::forStatus($outcome->status),
            AttemptResult::TransportFailure => self::forTransport($outcome->transportKind(), $operation),
        };
    }

    private static function forStatus(?int $status): RetryDecision
    {
        if ($status === null) {
            return RetryDecision::stop('the answer had no status');
        }

        if (in_array($status, self::RETRYABLE_STATUSES, true) || $status >= 500) {
            return RetryDecision::retry(sprintf('status %d is transient', $status));
        }

        return RetryDecision::stop(sprintf('status %d does not change on a repeat', $status));
    }

    private static function forTransport(?TransportFailureKind $kind, OperationType $operation): RetryDecision
    {
        if ($kind === null) {
            return RetryDecision::stop('the transport gave no failure kind');
        }

        // A repeated receipt lookup cannot double anything, so an unclear failure
        // is worth one more attempt there. A repeated send can.
        if (
            $operation === OperationType::Receipts
            && ($kind === TransportFailureKind::Unknown || $kind === TransportFailureKind::ClientFailure)
        ) {
            return RetryDecision::retry('a repeated receipt lookup cannot produce a duplicate');
        }

        return match ($kind) {
            TransportFailureKind::ConnectFailed,
            TransportFailureKind::NameResolutionFailed,
            TransportFailureKind::TlsFailed,
            TransportFailureKind::Timeout,
            TransportFailureKind::Interrupted => RetryDecision::retry(
                sprintf('%s is often transient', $kind->value)
            ),
            TransportFailureKind::TlsVerificationFailed => RetryDecision::stop(
                'the certificate did not verify, and a repeat cannot fix that'
            ),
            TransportFailureKind::InvalidConfiguration,
            TransportFailureKind::RequestConstruction => RetryDecision::stop(
                'the request itself is wrong, so a repeat cannot fix it'
            ),
            TransportFailureKind::ClientFailure,
            TransportFailureKind::Unknown => RetryDecision::stop(
                sprintf('%s does not say what went wrong, so the SDK does not repeat the send', $kind->value)
            ),
        };
    }
}
