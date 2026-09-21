<?php

declare(strict_types=1);

namespace Expo\Push\Protocol;

use Expo\Push\PushTicket;

/**
 * What the SDK read out of one send answer.
 */
final readonly class SendResponse implements ParsedResponse
{
    /**
     * @param ProtocolFailure|null    $failure         set when the whole answer is not usable
     * @param list<PushTicket|null>   $tickets         one entry for each notification of the request, null where the entry was malformed
     * @param list<ExpoApiError>      $apiErrors       request level errors that Expo reported
     * @param list<string>            $uncorrelatedIds receipt IDs that the SDK could read but cannot map to a device
     * @param list<string>            $warnings        contradictions that do not stop the SDK from using the answer
     */
    public function __construct(
        public ?ProtocolFailure $failure,
        public array $tickets = [],
        public array $apiErrors = [],
        public array $uncorrelatedIds = [],
        public array $warnings = [],
    ) {
    }

    public function isUsable(): bool
    {
        return $this->failure === null;
    }

    #[\Override]
    public function protocolFailure(): ?ProtocolFailure
    {
        return $this->failure;
    }

    /**
     * @return list<ExpoApiError>
     */
    #[\Override]
    public function errors(): array
    {
        return $this->apiErrors;
    }

    /**
     * @return list<string>
     */
    #[\Override]
    public function notes(): array
    {
        return $this->warnings;
    }
}
