<?php

declare(strict_types=1);

namespace Expo\Push;

/**
 * The delivery priority of a message.
 *
 * `High` wakes the device immediately. `Normal` lets the platform batch the message.
 */
enum Priority: string
{
    case Default = 'default';
    case Normal = 'normal';
    case High = 'high';
}
