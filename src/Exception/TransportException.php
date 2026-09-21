<?php

declare(strict_types=1);

namespace Expo\Push\Exception;

use Expo\Push\Http\TransportFailure;
use Expo\Push\Http\TransportFailureKind;
use RuntimeException;

/**
 * One HTTP attempt produced no response.
 *
 * A transport throws this. The SDK catches it inside the retry engine and turns
 * it into a structured attempt record, so `Expo::send()` does not raise it. Your
 * own transport code can catch it when you call a client directly.
 */
final class TransportException extends RuntimeException implements ExpoException
{
    public function __construct(public readonly TransportFailure $failure)
    {
        parent::__construct($failure->summary(), 0, $failure->previous);
    }

    public static function of(
        TransportFailureKind $kind,
        string $message,
        ?string $code = null,
        ?int $bytesUploaded = null,
        ?\Throwable $previous = null,
    ): self {
        return new self(TransportFailure::of($kind, $message, $code, $bytesUploaded, $previous));
    }
}
