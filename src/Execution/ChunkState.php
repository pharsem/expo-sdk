<?php

declare(strict_types=1);

namespace Expo\Push\Execution;

/**
 * Where one chunk is in its life.
 */
enum ChunkState: string
{
    /** The SDK never dispatched this chunk. */
    case Pending = 'pending';

    /** The chunk waits for a backoff or for a permit. */
    case Waiting = 'waiting';

    /** A request of this chunk is on the wire now. */
    case InFlight = 'in_flight';

    /** The chunk reached a final state. */
    case Done = 'done';
}
