<?php

declare(strict_types=1);

namespace Expo\Push\Observability;

use Expo\Push\Result\OperationType;

/**
 * The SDK stopped a chunk because the next attempt is too far away.
 *
 * The chunk is not lost. The result names its exact positions and the earliest
 * moment at which a retry makes sense.
 */
final readonly class ChunkDeferred extends Event
{
    public function __construct(
        string $operationId,
        OperationType $operation,
        int $atUtcMs,
        public int $chunk,
        public int $size,
        public ?int $earliestRetryAtUtcMs,
        public string $detail,
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
            'earliestRetryAtUtcMs' => $this->earliestRetryAtUtcMs,
            'detail' => $this->detail,
        ];
    }
}
