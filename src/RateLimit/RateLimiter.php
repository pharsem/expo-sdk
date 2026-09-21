<?php

declare(strict_types=1);

namespace Expo\Push\RateLimit;

/**
 * Asks for permission to send a number of notifications.
 *
 * Expo counts notifications, not requests, so the SDK asks for one permit for
 * each notification in the chunk. It asks again for every retry.
 *
 * Write your own implementation to share one limit between processes. Two rules
 * make that work:
 *
 * 1. `acquire()` must be atomic. Two workers must never both get the last permits
 *    of the same window. A Lua script, a stored procedure or a database
 *    transaction gives you that. A read, then a write, does not.
 * 2. `acquire()` must never sleep. Return `PermitDecision::wait()` and let the
 *    SDK schedule the wait, so other chunks keep moving.
 *
 * The SDK calls `acquire()` immediately before it dispatches a chunk.
 */
interface RateLimiter
{
    /**
     * @param string $bucket  the project or tenant key. Buckets never share a limit
     * @param int    $permits the number of notifications in this request, one or more
     */
    public function acquire(string $bucket, int $permits): PermitDecision;

    /**
     * The largest number of permits that one call can ever get, or null when the
     * limiter does not know.
     *
     * The SDK reads this at construction and refuses a chunk size that can never
     * fit, so that no request waits forever for a permit that cannot come.
     */
    public function capacity(): ?int;
}
