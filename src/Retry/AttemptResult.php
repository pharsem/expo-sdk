<?php

declare(strict_types=1);

namespace Expo\Push\Retry;

/**
 * What one attempt produced, after the SDK classified it.
 *
 * The four values stay apart on purpose. A 503 with an HTML body is an HTTP
 * failure that the SDK can retry. A 200 with a broken body is a protocol failure
 * that the SDK does not repeat.
 */
enum AttemptResult: string
{
    /** A 2xx answer with a body that the SDK could read. */
    case Success = 'success';

    /** An answer with a status that is not 2xx. */
    case HttpFailure = 'http_failure';

    /** No answer at all. */
    case TransportFailure = 'transport_failure';

    /** A 2xx answer that the SDK cannot trust. */
    case ProtocolFailure = 'protocol_failure';
}
