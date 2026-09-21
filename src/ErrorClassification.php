<?php

declare(strict_types=1);

namespace Expo\Push;

/**
 * What an Expo error code means for your application.
 *
 * The SDK never deletes a token and never resends a message for you. It tells
 * you what the code says, and it admits when the code says nothing useful.
 */
enum ErrorClassification: string
{
    /** The device no longer accepts notifications. Delete the token. */
    case TokenInvalid = 'token_invalid';

    /** The payload is too large. Make the message smaller. */
    case PayloadTooBig = 'payload_too_big';

    /** You send to this device too often. Slow down for that device. */
    case Throttled = 'throttled';

    /** The push credentials of the project are wrong. Fix them in the dashboard. */
    case Credentials = 'credentials';

    /** Expo, Apple or Google reported a problem. The code alone does not say whether a later send works. */
    case ProviderProblem = 'provider_problem';

    /** The SDK does not know this code. Read the raw code and the details. */
    case Unknown = 'unknown';

    /**
     * True when a later send of the same message can work, false when it cannot,
     * and null when the code does not say.
     *
     * A null answer is honest. Do not turn it into a resend loop.
     */
    public function maySucceedLater(): ?bool
    {
        return match ($this) {
            self::Throttled => true,
            self::TokenInvalid, self::PayloadTooBig, self::Credentials => false,
            self::ProviderProblem, self::Unknown => null,
        };
    }

    /**
     * True only when the evidence says that the token itself is dead.
     *
     * A payload error, a credential error and a provider problem all leave the
     * token valid.
     */
    public function invalidatesToken(): bool
    {
        return $this === self::TokenInvalid;
    }
}
