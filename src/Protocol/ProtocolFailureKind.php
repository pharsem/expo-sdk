<?php

declare(strict_types=1);

namespace Expo\Push\Protocol;

/**
 * Why the SDK cannot trust a 2xx answer.
 *
 * A protocol failure is not a transport failure. The request reached Expo and
 * Expo answered, so a repeat of the same send can produce a duplicate. The SDK
 * does not repeat it: it reports the acceptance as unknown.
 */
enum ProtocolFailureKind: string
{
    /** The body is not JSON. */
    case NotJson = 'not_json';

    /** The body is JSON but not a JSON object. */
    case EnvelopeNotObject = 'envelope_not_object';

    /** The answer has no `data` field. */
    case DataMissing = 'data_missing';

    /** The `data` field is a JSON array where the SDK needs an object, or the other way around. */
    case DataWrongType = 'data_wrong_type';

    /** The send answer holds a different number of tickets than the request held notifications. */
    case TicketCountMismatch = 'ticket_count_mismatch';

    /** Expo reported request level errors and sent no data. */
    case ErrorsWithoutData = 'errors_without_data';
}
