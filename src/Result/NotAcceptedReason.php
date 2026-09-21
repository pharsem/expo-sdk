<?php

declare(strict_types=1);

namespace Expo\Push\Result;

/**
 * Why a notification carries `Acceptance::NotAccepted`.
 */
enum NotAcceptedReason: string
{
    /**
     * Expo refused this notification.
     *
     * Either a ticket carries the error for this one device, or the server
     * answered the whole request with a 4xx status and acted on nothing.
     */
    case Rejected = 'rejected';

    /**
     * Every attempt failed before a byte left this process.
     *
     * The connection failed, the name did not resolve, the TLS handshake failed,
     * or the client refused to build the request. The operation was attempted,
     * so this is not `NotAttempted`.
     */
    case NotTransmitted = 'not_transmitted';
}
