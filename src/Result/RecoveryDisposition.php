<?php

declare(strict_types=1);

namespace Expo\Push\Result;

/**
 * What an application may do with one unresolved notification.
 *
 * The value answers a different question from `Acceptance`. Acceptance says what
 * Expo did. This says whether the work is still open, and whether a repeat needs
 * a decision first. Read both, and read `NotificationOutcome::duplicateRisk`
 * before you send anything again.
 */
enum RecoveryDisposition: string
{
    /**
     * There is nothing left to send again.
     *
     * Expo accepted the notification, or Expo refused it for a reason that a
     * repeat cannot fix, such as `DeviceNotRegistered` or `MessageTooBig`.
     */
    case None = 'none';

    /**
     * The work is open, and the failure behind it can pass on a later attempt.
     *
     * Wait until `NotificationOutcome::earliestRetryAtUtcMs`, then decide. The
     * SDK never sends it again for you.
     */
    case Retryable = 'retryable';

    /**
     * The work is open, and a repeat needs an application decision first.
     *
     * A credential failure, a limiter that broke, an answer that the SDK cannot
     * trust, and an error code that Expo does not explain all land here. Fix the
     * cause, or accept the risk, before you send the notification again.
     */
    case NeedsIntervention = 'needs_intervention';

    /**
     * True when the work is still open, whatever a repeat needs first.
     */
    public function isOpen(): bool
    {
        return $this !== self::None;
    }
}
