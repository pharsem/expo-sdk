<?php

declare(strict_types=1);

namespace Expo\Push\Observability;

use Expo\Push\Result\OperationType;

/**
 * The SDK dispatched one attempt of one chunk.
 */
final readonly class ChunkStarted extends Event
{
    public function __construct(
        string $operationId,
        OperationType $operation,
        int $atUtcMs,
        public int $chunk,
        public int $size,
        public int $attempt,
        public int $inFlight,
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
            'size' => $this->size,
            'attempt' => $this->attempt,
            'inFlight' => $this->inFlight,
        ];
    }
}
