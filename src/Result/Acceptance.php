<?php

declare(strict_types=1);

namespace Expo\Push\Result;

/**
 * What the SDK knows about one notification after a send.
 *
 * The four values are exclusive and they answer one question only: did Expo
 * accept this notification? They say nothing about a retry, about a duplicate,
 * or about what the device did. Read `NotificationOutcome::duplicateRisk` and
 * the receipt for those.
 */
enum Acceptance: string
{
    /**
     * A valid ticket, correlated to this notification, says that Expo took it.
     *
     * Expo accepted the notification. Apple or Google did not see it yet.
     */
    case Accepted = 'accepted';

    /**
     * The SDK has positive evidence that no attempt was accepted.
     *
     * Either Expo answered with an error ticket for this notification, or every
     * attempt failed before a single byte left this process. Read
     * `NotificationOutcome::reason` to tell the two apart.
     */
    case NotAccepted = 'not_accepted';

    /**
     * The SDK can neither confirm nor rule out acceptance.
     *
     * A timeout after transmission lands here. So does a 2xx answer that the SDK
     * cannot trust, and an ambiguous attempt that a later attempt rejected.
     */
    case Unknown = 'unknown';

    /**
     * The SDK never dispatched a request that held this notification.
     *
     * A chunk that the SDK skipped after an earlier failure lands here, and so
     * does a chunk that waits for a rate limit or a long `Retry-After`.
     */
    case NotAttempted = 'not_attempted';
}
