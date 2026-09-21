<?php

declare(strict_types=1);

namespace Expo\Push;

/**
 * An error code that Expo puts in a ticket or a receipt.
 *
 * Unknown codes stay available as the raw string on the ticket or the receipt.
 */
enum PushError: string
{
    /** The device no longer wants this notification. Delete the token. */
    case DeviceNotRegistered = 'DeviceNotRegistered';

    /** The message is larger than 4096 bytes. */
    case MessageTooBig = 'MessageTooBig';

    /** You send to this device too often. Slow down. */
    case MessageRateExceeded = 'MessageRateExceeded';

    /** The FCM sender ID of the token does not match the server key. */
    case MismatchSenderId = 'MismatchSenderId';

    /** The push credentials of the project are invalid or revoked. */
    case InvalidCredentials = 'InvalidCredentials';

    /** The Apple push key or the provisioning profile is not valid. */
    case InvalidProviderToken = 'InvalidProviderToken';

    /** Expo could not deliver the message. */
    case ExpoError = 'ExpoError';

    /** Apple or Google rejected the message. */
    case ProviderError = 'ProviderError';

    /**
     * True when the token is dead and you must delete it from your database.
     */
    public function isPermanent(): bool
    {
        return $this === self::DeviceNotRegistered;
    }

    /**
     * True when a later send of the same message can work.
     */
    public function isRetryable(): bool
    {
        return match ($this) {
            self::MessageRateExceeded, self::ExpoError, self::ProviderError => true,
            default => false,
        };
    }
}
