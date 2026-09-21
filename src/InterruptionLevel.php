<?php

declare(strict_types=1);

namespace Expo\Push;

/**
 * How much an iOS notification interrupts the user.
 */
enum InterruptionLevel: string
{
    case Active = 'active';
    case Critical = 'critical';
    case Passive = 'passive';
    case TimeSensitive = 'time-sensitive';
}
