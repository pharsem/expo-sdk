<?php

declare(strict_types=1);

namespace Expo\Push\Observability;

use Expo\Push\Result\OperationType;

/**
 * The SDK will wait before it dispatches one chunk again.
 *
 * The wait never blocks the chunks that are already in flight.
 */
final readonly class WaitScheduled extends Event
{
    public function __construct(
        string $operationId,
        OperationType $operation,
        int $atUtcMs,
        public int $chunk,
        public WaitReason $reason,
        public int $delayMs,
        public int $nextAttempt,
        public ?string $detail = null,
    ) {
        parent::__construct($operationId, $operation, $atUtcMs);
    }

    #[\Override]
    public function fields(): array
    {
        return [
            'operationId' => $this->operationId,
            'operation' => $this->operation->value,
            'chunk' => $this->chunk,
            'reason' => $this->reason->value,
            'delayMs' => $this->delayMs,
            'nextAttempt' => $this->nextAttempt,
            'detail' => $this->detail,
        ];
    }
}
