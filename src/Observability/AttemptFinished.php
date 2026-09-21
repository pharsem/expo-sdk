<?php

declare(strict_types=1);

namespace Expo\Push\Observability;

use Expo\Push\Result\OperationType;
use Expo\Push\Result\AttemptRecord;

/**
 * One attempt of one chunk produced an answer or a failure.
 */
final readonly class AttemptFinished extends Event
{
    public function __construct(
        string $operationId,
        OperationType $operation,
        int $atUtcMs,
        public int $chunk,
        public AttemptRecord $record,
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
            'attempt' => $this->record->number,
            'result' => $this->record->result->value,
            'status' => $this->record->status,
            'code' => $this->record->code,
            'transmission' => $this->record->transmission->value,
            'durationMs' => $this->record->durationMs,
            'summary' => $this->record->summary,
        ];
    }
}
