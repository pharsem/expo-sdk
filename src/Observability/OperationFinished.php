<?php

declare(strict_types=1);

namespace Expo\Push\Observability;

use Expo\Push\Result\OperationType;

/**
 * Every chunk of the operation reached a final state.
 */
final readonly class OperationFinished extends Event
{
    /**
     * @param array<string, int> $summary the counts of the result
     */
    public function __construct(
        string $operationId,
        OperationType $operation,
        int $atUtcMs,
        public int $durationMs,
        public array $summary,
        public int $observerFailures = 0,
    ) {
        parent::__construct($operationId, $operation, $atUtcMs);
    }

    #[\Override]
    public function fields(): array
    {
        $fields = [
            'operationId' => $this->operationId,
            'operation' => $this->operation->value,
            'durationMs' => $this->durationMs,
            'observerFailures' => $this->observerFailures,
        ];

        foreach ($this->summary as $key => $value) {
            $fields[$key] = $value;
        }

        return $fields;
    }
}
