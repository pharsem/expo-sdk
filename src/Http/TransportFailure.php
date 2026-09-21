<?php

declare(strict_types=1);

namespace Expo\Push\Http;

use Expo\Push\Support\Redact;
use Throwable;

/**
 * One HTTP attempt that produced no response.
 *
 * The object keeps the original exception for your own diagnostics. The SDK
 * itself only reads `kind`, `code` and `transmission`.
 */
final readonly class TransportFailure
{
    /**
     * @param TransportFailureKind $kind          why the attempt failed
     * @param string               $message       a short text, already redacted
     * @param string|null          $code          the cURL error name, or the class of the client exception
     * @param int|null             $bytesUploaded the request bytes that left this process, when the transport counts them
     * @param Throwable|null       $previous      the original exception, for your logs
     */
    public function __construct(
        public TransportFailureKind $kind,
        public string $message,
        public ?string $code = null,
        public ?int $bytesUploaded = null,
        public ?Throwable $previous = null,
    ) {
    }

    public static function of(
        TransportFailureKind $kind,
        string $message,
        ?string $code = null,
        ?int $bytesUploaded = null,
        ?Throwable $previous = null,
    ): self {
        return new self($kind, Redact::text($message) ?? $kind->value, $code, $bytesUploaded, $previous);
    }

    /**
     * What the SDK knows about the request bytes.
     *
     * A byte counter above zero never proves that the server accepted anything.
     * It only proves that the SDK cannot rule the request out.
     */
    public function transmission(): Transmission
    {
        // A byte counter above zero is evidence that bytes left this process.
        // It never proves that the server accepted anything, so it can only
        // move the answer toward Unknown, never toward a clean rejection.
        if ($this->bytesUploaded !== null && $this->bytesUploaded > 0) {
            return Transmission::Unknown;
        }

        return $this->kind->transmission();
    }

    /**
     * A log line without a token, a credential or a body.
     */
    public function summary(): string
    {
        return $this->code === null
            ? sprintf('%s: %s', $this->kind->value, $this->message)
            : sprintf('%s (%s): %s', $this->kind->value, $this->code, $this->message);
    }
}
