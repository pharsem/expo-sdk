<?php

declare(strict_types=1);

namespace Expo\Push\Exception;

/**
 * The message is larger than the Expo limit of 4096 bytes.
 */
final class MessageTooLargeException extends InvalidMessageException
{
    public function __construct(
        public readonly int $size,
        public readonly int $limit,
    ) {
        parent::__construct(sprintf(
            'The message is %d bytes. Expo accepts %d bytes. Move large values out of the data field.',
            $size,
            $limit
        ));
    }
}
