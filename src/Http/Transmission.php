<?php

declare(strict_types=1);

namespace Expo\Push\Http;

/**
 * What the SDK knows about the request bytes of one attempt.
 *
 * `Transmitted` means that the bytes left this process. It does not mean that
 * Expo read them, and it never means that Expo accepted the notifications.
 */
enum Transmission: string
{
    /** The SDK knows that no request byte reached the network. */
    case NotTransmitted = 'not_transmitted';

    /** The SDK cannot tell whether the server saw the request. */
    case Unknown = 'unknown';

    /** The SDK sent the whole request body. The answer is still unknown. */
    case Transmitted = 'transmitted';
}
