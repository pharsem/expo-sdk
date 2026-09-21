<?php

declare(strict_types=1);

namespace Expo\Push\Exception;

use Expo\Push\Result\ReceiptResult;
use Expo\Push\Result\RequestFailure;
use RuntimeException;

/**
 * At least one receipt lookup produced no usable answer.
 *
 * Only `ReceiptResult::throwIfLookupFailed()` raises this. `Expo::receipts()`
 * returns the result instead, so every receipt that did arrive stays available.
 */
final class ReceiptLookupFailedException extends RuntimeException implements ExpoException
{
    public function __construct(public readonly ReceiptResult $result)
    {
        parent::__construct(self::describe($result));
    }

    private static function describe(ReceiptResult $result): string
    {
        $summary = $result->summary();
        $first = $result->requestFailures[0] ?? null;

        return sprintf(
            '%d receipt request(s) failed. returned=%d, missing=%d, failed=%d, notAttempted=%d. First: %s',
            count($result->requestFailures),
            $summary['returned'],
            $summary['missing'],
            $summary['failed'],
            $summary['notAttempted'],
            $first instanceof RequestFailure ? $first->summary() : 'unknown'
        );
    }
}
