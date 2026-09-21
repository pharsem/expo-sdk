<?php

declare(strict_types=1);

namespace Expo\Push\Http;

/**
 * Why one HTTP attempt did not produce a response.
 *
 * The retry policy reads this enum. A normal 4xx or 5xx response is not a
 * transport failure: it reaches the protocol layer as a response.
 */
enum TransportFailureKind: string
{
    /** The SDK could not open the connection. Nothing left this process. */
    case ConnectFailed = 'connect_failed';

    /** The SDK could not resolve the host name. Nothing left this process. */
    case NameResolutionFailed = 'name_resolution_failed';

    /** The TLS handshake failed. Nothing left this process. */
    case TlsFailed = 'tls_failed';

    /** The server certificate did not verify. Never retry this. */
    case TlsVerificationFailed = 'tls_verification_failed';

    /** The attempt ran out of time. The server can still have the request. */
    case Timeout = 'timeout';

    /** The transfer stopped in the middle. The server can still have the request. */
    case Interrupted = 'interrupted';

    /** The transport settings are wrong. Fix the code. */
    case InvalidConfiguration = 'invalid_configuration';

    /** A PSR-18 client could not build the request. Nothing left this process. */
    case RequestConstruction = 'request_construction';

    /** A PSR-18 client failed for a reason that is not a network error. */
    case ClientFailure = 'client_failure';

    /** The SDK has no better name for the failure. */
    case Unknown = 'unknown';

    /**
     * What the SDK knows about the request bytes after this failure.
     */
    public function transmission(): Transmission
    {
        return match ($this) {
            self::ConnectFailed,
            self::NameResolutionFailed,
            self::TlsFailed,
            self::TlsVerificationFailed,
            self::InvalidConfiguration,
            self::RequestConstruction => Transmission::NotTransmitted,
            default => Transmission::Unknown,
        };
    }

    /**
     * True when the SDK knows that the request never reached the network.
     */
    public function isBeforeTransmission(): bool
    {
        return $this->transmission() === Transmission::NotTransmitted;
    }
}
