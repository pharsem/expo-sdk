<?php

declare(strict_types=1);

namespace Expo\Push\Observability;

/**
 * Does nothing. This is the default.
 */
final readonly class NullObserver implements Observer
{
    #[\Override]
    public function onEvent(Event $event): void
    {
    }
}
