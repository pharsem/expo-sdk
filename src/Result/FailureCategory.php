<?php

declare(strict_types=1);

namespace Expo\Push\Result;

/**
 * What kind of problem ended one request.
 *
 * The categories stay apart because they need different answers from you.
 */
enum FailureCategory: string
{
    /** A status that the SDK will not retry, such as 401 or 400. */
    case Http = 'http';

    /** HTTP 429, or a local rate limiter that refused the permits. */
    case RateLimited = 'rate_limited';

    /** No answer at all: a connection failure, a timeout, an interrupted transfer. */
    case Transport = 'transport';

    /** A 2xx answer that the SDK cannot trust. */
    case Protocol = 'protocol';

    /** Expo returned request level errors, such as `PUSH_TOO_MANY_NOTIFICATIONS`. */
    case Api = 'api';

    /** The chunk budget or the operation deadline ran out. */
    case Deadline = 'deadline';

    /** The rate limiter itself failed. The SDK stops rather than send without a limit. */
    case Limiter = 'limiter';

    /** The SDK skipped this chunk after an earlier chunk failed. */
    case Skipped = 'skipped';
}
