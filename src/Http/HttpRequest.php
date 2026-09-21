<?php

declare(strict_types=1);

namespace Expo\Push\Http;

/**
 * One request that a transport must send.
 *
 * The SDK builds the whole request, including the timeouts. A transport must not
 * change the URL, the body or the headers, and must not repeat the request: the
 * retry engine of the SDK owns every attempt.
 */
final readonly class HttpRequest
{
    /**
     * @param string                $url              the full URL
     * @param string                $body             the request body, already compressed when the SDK compresses it
     * @param array<string, string> $headers          header names in lower case
     * @param int                   $timeoutMs        the whole attempt, in milliseconds
     * @param int                   $connectTimeoutMs the connection, in milliseconds
     */
    public function __construct(
        public string $url,
        public string $body,
        public array $headers,
        public int $timeoutMs,
        public int $connectTimeoutMs,
    ) {
    }

    /**
     * A copy with a shorter total timeout, for a chunk that is near its budget.
     */
    public function withTimeoutMs(int $timeoutMs): self
    {
        return new self(
            $this->url,
            $this->body,
            $this->headers,
            max(1, $timeoutMs),
            min($this->connectTimeoutMs, max(1, $timeoutMs)),
        );
    }

    public function byteSize(): int
    {
        return strlen($this->body);
    }
}
