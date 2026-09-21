<?php

declare(strict_types=1);

namespace Expo\Push\Plan;

/**
 * The encoded body of one message, without the `to` field.
 *
 * The plan holds one payload for each message, whatever the number of devices.
 * A chunk adds the `to` field for its own slice, so a send to 100 devices copies
 * one small array and not 100 full messages.
 */
final readonly class MessagePayload
{
    /**
     * @param array<string, mixed> $base          the Expo fields, without `to`
     * @param int|string           $key           the key or the ordinal of the message in your input
     * @param string|null          $reference     your own correlation value
     * @param int                  $sizeEstimate  the byte size of `$base`, for the preflight check
     */
    public function __construct(
        public array $base,
        public int|string $key,
        public ?string $reference = null,
        public int $sizeEstimate = 0,
    ) {
    }

    /**
     * The request body of this message for one slice of its devices.
     *
     * @param list<string> $tokens
     *
     * @return array<string, mixed>
     */
    public function bodyFor(array $tokens): array
    {
        $payload = $this->base;
        $payload['to'] = count($tokens) === 1 ? $tokens[0] : $tokens;

        return $payload;
    }
}
