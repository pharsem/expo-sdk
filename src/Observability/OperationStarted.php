<?php

declare(strict_types=1);

namespace Expo\Push\Observability;

use Expo\Push\Result\OperationType;

/**
 * The SDK normalized the input and is about to send the first request.
 */
final readonly class OperationStarted extends Event
{
    public function __construct(
        string $operationId,
        OperationType $operation,
        int $atUtcMs,
        public int $items,
        public int $chunks,
        public int $concurrency,
    ) {
        parent::__construct($operationId, $operation, $atUtcMs);
    }

    #[\Override]
    public function fields(): array
    {
        return [
            'operationId' => $this->operationId,
            'operation' => $this->operation->value,
            'items' => $this->items,
            'chunks' => $this->chunks,
            'concurrency' => $this->concurrency,
        ];
    }
}
