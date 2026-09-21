<?php

declare(strict_types=1);

namespace Expo\Push\Plan;

use Expo\Push\PushToken;

/**
 * One send request: a list of notifications that fits the Expo limit.
 */
final readonly class SendChunk
{
    /**
     * @param int                        $ordinal the position of the chunk in the operation, from 0
     * @param list<int>                  $indexes the notification index of every notification in this chunk
     * @param list<PushToken>            $tokens  the device of every notification, in the same order
     * @param list<array<string, mixed>> $body    the request body: one entry for each message slice
     */
    public function __construct(
        public int $ordinal,
        public array $indexes,
        public array $tokens,
        public array $body,
    ) {
    }

    /**
     * The number of notifications in this chunk. This is what Expo counts.
     */
    public function count(): int
    {
        return count($this->indexes);
    }

    /**
     * The first and the last notification index of this chunk.
     *
     * The last chunk of an operation is often shorter than the others, and this
     * range always shows the real length.
     *
     * @return array{0: int, 1: int}
     */
    public function range(): array
    {
        $first = $this->indexes[0] ?? 0;

        return [$first, $this->indexes[count($this->indexes) - 1] ?? $first];
    }
}
