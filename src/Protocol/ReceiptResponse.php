<?php

declare(strict_types=1);

namespace Expo\Push\Protocol;

use Expo\Push\PushReceipt;

/**
 * What the SDK read out of one receipt answer.
 */
final readonly class ReceiptResponse implements ParsedResponse
{
    /**
     * @param ProtocolFailure|null           $failure      set when the whole answer is not usable
     * @param array<string, PushReceipt>     $receipts     the valid receipts, keyed by receipt ID
     * @param list<string>                   $malformedIds requested IDs whose entry the SDK could not read
     * @param list<string>                   $unexpectedIds IDs that the SDK did not ask for
     * @param list<ExpoApiError>             $apiErrors    request level errors that Expo reported
     * @param list<string>                   $warnings     contradictions that do not stop the SDK from using the answer
     */
    public function __construct(
        public ?ProtocolFailure $failure,
        public array $receipts = [],
        public array $malformedIds = [],
        public array $unexpectedIds = [],
        public array $apiErrors = [],
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
