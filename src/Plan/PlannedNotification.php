<?php

declare(strict_types=1);

namespace Expo\Push\Plan;

use Expo\Push\PushToken;

/**
 * One message and one device: the unit that the SDK counts, sends and reports.
 *
 * A notification is not a unique token and not a message object. Two messages to
 * the same device are two notifications. Two copies of the same device inside one
 * message are one notification, and the first position wins.
 *
 * The identity survives the chunk split, the retries, an answer that arrives out
 * of order, the result and the receipt lookup. The SDK never rebuilds it from a
 * token string.
 */
final readonly class PlannedNotification
{
    /**
     * @param int        $index           the stable position in this operation, from 0
     * @param int|string $messageKey      the key or the ordinal of the message in your input
     * @param int        $messageOrdinal  the position of the message in this operation, from 0
     * @param int        $recipientIndex  the position of the device inside the message, after deduplication
     * @param PushToken  $token           the device
     * @param string|null $reference      your own correlation value from the message
     */
    public function __construct(
        public int $index,
        public int|string $messageKey,
        public int $messageOrdinal,
        public int $recipientIndex,
        public PushToken $token,
        public ?string $reference = null,
    ) {
    }
}
