<?php

declare(strict_types=1);

namespace Expo\Push\Observability;

/**
 * Why the SDK waits before it dispatches a chunk.
 */
enum WaitReason: string
{
    /** The backoff before a retry. */
    case Retry = 'retry';

    /** The rate limiter refused the permits for now. */
    case RateLimit = 'rate_limit';
}
