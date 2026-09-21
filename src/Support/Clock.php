<?php

declare(strict_types=1);

namespace Expo\Push\Support;

/**
 * The two clocks that the SDK needs.
 *
 * The SDK measures every budget and every backoff with the monotonic clock,
 * because the wall clock can jump. It writes a stored retry time with the wall
 * clock, because a monotonic value means nothing in another process.
 */
interface Clock
{
    /**
     * The UTC wall time in milliseconds since the Unix epoch.
     *
     * Use it for an HTTP date, and for a timestamp that you store.
     */
    public function nowUtcMillis(): int;

    /**
     * A monotonic counter in milliseconds.
     *
     * The value has no meaning outside this process. Never store it.
     */
    public function monotonicMillis(): int;
}
