<?php

declare(strict_types=1);

namespace Expo\Push\Observability;

use Expo\Push\Result\OperationType;
use Expo\Push\Result\FailureCategory;

/**
 * One chunk reached a final state, with or without a failure.
 */
final readonly class ChunkFinished extends Event
{
    public function __construct(
        string $operationId,
        OperationType $operation,
        int $atUtcMs,
        public int $chunk,
        public int $size,
        public int $attempts,
        public int $durationMs,
        public bool $succeeded,
        public ?FailureCategory $category = null,
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
            'attempts' => $this->attempts,
            'durationMs' => $this->durationMs,
            'succeeded' => $this->succeeded,
            'category' => $this->category?->value,
        ];
    }
}
