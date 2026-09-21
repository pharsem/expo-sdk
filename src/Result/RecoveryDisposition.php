<?php

declare(strict_types=1);

namespace Expo\Push\Result;

use Expo\Push\ErrorClassification;

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

    /**
     * What one rejection of one device leaves open.
     *
     * Only two kinds of rejection close the work: a device that no longer
     * accepts notifications, and a payload that is too large. Both of them need
     * a different message or a different device, so a repeat of this one cannot
     * pass.
     *
     * A credential error is not one of them. The token stays valid, and the
     * notification goes out after somebody fixes the credentials.
     *
     * @param ErrorClassification|null $classification the error of the ticket
     *                                                 or the receipt, or null
     *                                                 when there is no error
     */
    public static function forClassification(?ErrorClassification $classification): self
    {
        return match ($classification) {
            null => self::None,
            ErrorClassification::TokenInvalid, ErrorClassification::PayloadTooBig => self::None,
            ErrorClassification::Throttled => self::Retryable,
            ErrorClassification::Credentials,
            ErrorClassification::ProviderProblem,
            ErrorClassification::Unknown => self::NeedsIntervention,
        };
    }
}
