<?php

declare(strict_types=1);

namespace Expo\Push\Plan;

use Expo\Push\Exception\InvalidMessageException;
use Expo\Push\PushToken;
use Expo\Push\Result\ReceiptReference;

/**
 * Every receipt ID of one lookup operation, in input order and without repeats.
 *
 * The plan keeps every reference that pointed at an ID, so a duplicate lookup
 * request still maps its answer back to each notification that asked for it.
 */
final readonly class ReceiptPlan
{
    /**
     * @param list<string>                    $ids           the unique IDs, in first seen order
     * @param array<string, ReceiptReference> $referencesById the first reference of each ID
     * @param array<string, list<ReceiptReference>> $allReferencesById every reference of each ID
     */
    public function __construct(
        public array $ids = [],
        public array $referencesById = [],
        public array $allReferencesById = [],
    ) {
    }

    public function count(): int
    {
        return count($this->ids);
    }

    public function isEmpty(): bool
    {
        return $this->ids === [];
    }

    /**
     * @return array<string, PushToken>
     */
    public function tokensById(): array
    {
        $tokens = [];

        foreach ($this->referencesById as $id => $reference) {
            if ($reference->token !== null) {
                $tokens[$id] = $reference->token;
            }
        }

        return $tokens;
    }

    /**
     * Splits the plan into requests of at most `$size` IDs.
     *
     * @return list<ReceiptChunk>
     *
     * @throws InvalidMessageException when the size is below one
     */
    public function chunks(int $size): array
    {
        if ($size < 1) {
            throw new InvalidMessageException('The chunk size must be 1 or more.');
        }

        $chunks = [];
        $ordinal = 0;

        foreach (array_chunk($this->ids, $size) as $slice) {
            $chunks[] = new ReceiptChunk($ordinal++, array_values($slice));
        }

        return $chunks;
    }
}
