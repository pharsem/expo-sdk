<?php

declare(strict_types=1);

namespace Expo\Push\Exception;

use Expo\Push\Result\RequestFailure;
use Expo\Push\Result\SendResult;
use RuntimeException;

/**
 * At least one send request produced no usable answer.
 *
 * Only `SendResult::throwIfRequestFailed()` raises this. `Expo::send()` returns
 * the result instead, so nothing is ever lost.
 *
 * The exception carries the whole result. Read `$exception->result` for the
 * tickets that did work, for the unknown acceptances and for the exact positions
 * that the SDK never tried.
 */
final class SendFailedException extends RuntimeException implements ExpoException
{
    public function __construct(public readonly SendResult $result)
    {
        parent::__construct(self::describe($result));
    }

    private static function describe(SendResult $result): string
    {
        $summary = $result->summary();

        $first = $result->requestFailures[0] ?? null;

        return sprintf(
            '%d send request(s) failed. accepted=%d, unknown=%d, notAccepted=%d, notAttempted=%d. First: %s',
            count($result->requestFailures),
            $summary['accepted'],
            $summary['unknown'],
            $summary['notAccepted'],
            $summary['notAttempted'],
            $first instanceof RequestFailure ? $first->summary() : 'unknown'
        );
    }
}
