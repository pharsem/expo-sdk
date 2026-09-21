<?php

declare(strict_types=1);

namespace Expo\Push;

/**
 * An error code that Expo puts in a ticket or a receipt.
 *
 * An unknown code stays available as the raw string on the ticket or the receipt,
 * with the classification `ErrorClassification::Unknown`.
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
     * What this code means for your application.
     */
    public function classification(): ErrorClassification
    {
        return match ($this) {
            self::DeviceNotRegistered => ErrorClassification::TokenInvalid,
            self::MessageTooBig => ErrorClassification::PayloadTooBig,
            self::MessageRateExceeded => ErrorClassification::Throttled,
            self::MismatchSenderId,
            self::InvalidCredentials,
            self::InvalidProviderToken => ErrorClassification::Credentials,
            self::ExpoError, self::ProviderError => ErrorClassification::ProviderProblem,
        };
    }

    /**
     * True only for `DeviceNotRegistered`. Delete that token from your database.
     *
     * A lasting error does not always kill the token. A credential error lasts
     * until you fix the credentials, and the token stays valid.
     */
    public function invalidatesToken(): bool
    {
        return $this->classification()->invalidatesToken();
    }

    /**
     * True, false, or null when the code does not say. See `ErrorClassification`.
     */
    public function maySucceedLater(): ?bool
    {
        return $this->classification()->maySucceedLater();
    }
}
