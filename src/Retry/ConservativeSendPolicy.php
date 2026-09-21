<?php

declare(strict_types=1);

namespace Expo\Push\Retry;

use Expo\Push\Result\OperationType;

/**
 * A send policy for work where a duplicate costs more than a lost notification.
 *
 * It repeats a send only when the SDK knows that Expo did not apply the attempt:
 *
 * - A failure before transmission: the connection failed, the name did not
 *   resolve, the TLS handshake failed, or the client refused to build the request.
 * - An explicit rate limit rejection: HTTP 429. The server answered and did
 *   nothing with the notifications.
 *
 * It never repeats a timeout, an interrupted transfer, a 5xx status or a generic
 * PSR-18 network exception. None of those say what the server did. Unknown stays
 * unknown, and the result reports `Acceptance::Unknown` for those notifications.
 *
 * A receipt lookup has no duplicate risk, so this policy falls back to the
 * delivery rules for `OperationType::Receipts`.
 */
final readonly class ConservativeSendPolicy implements RetryPolicy
{
    private DeliveryRetryPolicy $delivery;

    public function __construct(private RetrySettings $settings = new RetrySettings())
    {
        $this->delivery = new DeliveryRetryPolicy($settings);
    }

    #[\Override]
    public function settings(): RetrySettings
    {
        return $this->settings;
    }

    #[\Override]
    public function decide(OperationType $operation, int $attempt, AttemptOutcome $outcome): RetryDecision
    {
        if ($operation === OperationType::Receipts) {
            return $this->delivery->decide($operation, $attempt, $outcome);
        }

        if ($attempt >= $this->settings->maxAttempts) {
            return RetryDecision::stop(sprintf('the policy allows %d attempts', $this->settings->maxAttempts));
        }

        if ($outcome->isSuccess()) {
            return RetryDecision::stop('the request succeeded');
        }

        if ($outcome->status === 429) {
            return RetryDecision::retry('status 429 says that the server applied nothing');
        }

        if ($outcome->result === AttemptResult::TransportFailure && $outcome->isBeforeTransmission()) {
            return RetryDecision::retry('the request never reached the network');
        }

        return RetryDecision::stop(
            'the SDK cannot prove that Expo ignored this attempt, so a repeat can produce a duplicate'
        );
    }
}
