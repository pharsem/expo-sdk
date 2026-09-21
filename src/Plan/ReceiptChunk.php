<?php

declare(strict_types=1);

namespace Expo\Push\Plan;

/**
 * One receipt lookup request: a list of unique receipt IDs.
 */
final readonly class ReceiptChunk
{
    /**
     * @param int          $ordinal the position of the chunk in the operation, from 0
     * @param list<string> $ids     the unique receipt IDs of this request
     */
    public function __construct(
        public int $ordinal,
        public array $ids,
    ) {
    }

    public function count(): int
    {
        return count($this->ids);
    }

    /**
     * @return array<string, mixed>
     */
    public function body(): array
    {
        return ['ids' => $this->ids];
    }
}
