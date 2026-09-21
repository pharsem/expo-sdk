<?php

declare(strict_types=1);

namespace Expo\Push\Plan;

use Expo\Push\Exception\InvalidMessageException;
use Expo\Push\PushToken;

/**
 * Every notification of one send operation, in input order.
 *
 * `Expo::send()` builds the whole plan before the first request. That costs
 * memory for a very large input, and it buys one thing that matters: an invalid
 * message in the last chunk raises an exception before the first chunk goes out.
 *
 * Use `Expo::lazyChunks()` when you own the streaming and accept that there is
 * no whole operation check.
 */
final readonly class SendPlan
{
    /**
     * @param list<PlannedNotification> $notifications one entry for each message and device pair
     * @param list<MessagePayload>      $payloads      one entry for each message, shared by its devices
     */
    public function __construct(
        public array $notifications = [],
        public array $payloads = [],
    ) {
    }

    public function count(): int
    {
        return count($this->notifications);
    }

    public function isEmpty(): bool
    {
        return $this->notifications === [];
    }

    public function notification(int $index): ?PlannedNotification
    {
        return $this->notifications[$index] ?? null;
    }

    /**
     * Splits the plan into requests of at most `$size` notifications.
     *
     * @return list<SendChunk>
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

        foreach (array_chunk($this->notifications, $size) as $slice) {
            $chunks[] = $this->buildChunk($ordinal++, $slice);
        }

        return $chunks;
    }

    /**
     * @param list<PlannedNotification> $slice
     */
    private function buildChunk(int $ordinal, array $slice): SendChunk
    {
        $indexes = [];
        $tokens = [];
        $body = [];

        $currentOrdinal = null;
        $currentTokens = [];

        foreach ($slice as $notification) {
            $indexes[] = $notification->index;
            $tokens[] = $notification->token;

            if ($currentOrdinal !== null && $currentOrdinal !== $notification->messageOrdinal) {
                $body[] = $this->payloads[$currentOrdinal]->bodyFor($currentTokens);
                $currentTokens = [];
            }

            $currentOrdinal = $notification->messageOrdinal;
            $currentTokens[] = $notification->token->value;
        }

        if ($currentOrdinal !== null) {
            $body[] = $this->payloads[$currentOrdinal]->bodyFor($currentTokens);
        }

        return new SendChunk($ordinal, $indexes, $tokens, $body);
    }

    /**
     * Every device of the plan, in input order, with repeats.
     *
     * @return list<PushToken>
     */
    public function tokens(): array
    {
        return array_map(
            static fn (PlannedNotification $notification): PushToken => $notification->token,
            $this->notifications
        );
    }
}
