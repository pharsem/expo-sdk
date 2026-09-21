<?php

declare(strict_types=1);

namespace Expo\Push\Result;

/**
 * What the SDK knows about one requested receipt ID.
 *
 * The five values stay apart. "Missing" is not "failed", and neither is "not
 * attempted". Only a `Returned` entry holds a receipt.
 */
enum ReceiptState: string
{
    /** Expo answered with a valid receipt. Read `PushReceipt::status` for `ok` or `error`. */
    case Returned = 'returned';

    /**
     * The lookup worked and the answer held no entry for this ID.
     *
     * Missing can mean three things: the receipt is not ready, the ID is not
     * valid, or Expo no longer keeps the receipt. The answer does not say which.
     */
    case Missing = 'missing';

    /** The answer held an entry for this ID that the SDK could not read. */
    case Malformed = 'malformed';

    /** The request for this ID produced no usable answer. Read the request failure. */
    case LookupFailed = 'lookup_failed';

    /** The SDK never sent a request that held this ID. */
    case NotAttempted = 'not_attempted';
}
