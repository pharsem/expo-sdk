<?php

declare(strict_types=1);

namespace Expo\Push\Observability;

/**
 * Watches an operation without changing it.
 *
 * An observer cannot stop a retry, cannot change a result and cannot drop a
 * completed chunk. The SDK isolates every call: an exception inside an observer
 * never reaches your `send()` call and never erases a success. The SDK counts
 * the failures, reports the count one time at the end, and stops calling an
 * observer that fails again and again.
 *
 * Every field of every event is safe to log. See `Event`.
 */
interface Observer
{
    public function onEvent(Event $event): void;
}
