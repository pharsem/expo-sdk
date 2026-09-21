<?php

declare(strict_types=1);

namespace Expo\Push\Retry;

use Expo\Push\Http\Transmission;
use Expo\Push\Http\TransportFailure;
use Expo\Push\Http\TransportFailureKind;
use Expo\Push\Protocol\ProtocolFailure;

/**
 * One finished attempt, classified for the retry policy.
 *
 * The object holds the facts. It holds no decision: the policy reads it and the
 * engine computes the delay.
 */
final readonly class AttemptOutcome
{
    /**
     * @param AttemptResult         $result         what the attempt produced
     * @param int|null              $status         the HTTP status, when there was an answer
     * @param TransportFailure|null $transport      the transport failure, when there was no answer
     * @param ProtocolFailure|null  $protocol       the protocol failure, when the body was not trustworthy
     * @param int|null              $serverDelayMs  the `Retry-After` value in milliseconds
     * @param Transmission          $transmission   what the SDK knows about the request bytes
     * @param int|null              $durationMs     how long the attempt took
     */
    public function __construct(
        public AttemptResult $result,
        public ?int $status = null,
        public ?TransportFailure $transport = null,
        public ?ProtocolFailure $protocol = null,
        public ?int $serverDelayMs = null,
        public Transmission $transmission = Transmission::Unknown,
        public ?int $durationMs = null,
    ) {
    }

    public function isSuccess(): bool
    {
        return $this->result === AttemptResult::Success;
    }

    /**
     * True when the SDK knows that this attempt never reached the network.
     */
    public function isBeforeTransmission(): bool
    {
        return $this->transmission === Transmission::NotTransmitted;
    }

    /**
     * True when the server answered with a 4xx status.
     *
     * A 4xx answer says that the server refused the request. It did not accept
     * any notification of it, so the acceptance is not ambiguous.
     */
    public function serverRefused(): bool
    {
        return $this->result === AttemptResult::HttpFailure
            && $this->status !== null
            && $this->status >= 400
            && $this->status < 500;
    }

    /**
     * True when the SDK cannot rule out that Expo acted on this attempt.
     *
     * A success is not ambiguous: the answer says what happened. A 4xx answer is
     * not ambiguous either: the server refused the request. Everything else is.
     */
    public function isAmbiguous(): bool
    {
        if ($this->isSuccess() || $this->serverRefused()) {
            return false;
        }

        return $this->transmission !== Transmission::NotTransmitted;
    }

    public function transportKind(): ?TransportFailureKind
    {
        return $this->transport?->kind;
    }

    /**
     * A short code for a log line: the HTTP status, the cURL error, or the
     * protocol failure kind.
     */
    public function code(): ?string
    {
        if ($this->status !== null) {
            return (string) $this->status;
        }

        $code = $this->transport?->code;

        return $code ?? $this->protocol?->kind->value;
    }
}
