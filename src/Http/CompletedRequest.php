<?php

declare(strict_types=1);

namespace Expo\Push\Http;

/**
 * One finished request of a concurrent transport.
 */
final readonly class CompletedRequest
{
    private function __construct(
        public int $id,
        public ?HttpResponse $response,
        public ?TransportFailure $failure,
    ) {
    }

    public static function response(int $id, HttpResponse $response): self
    {
        return new self($id, $response, null);
    }

    public static function failure(int $id, TransportFailure $failure): self
    {
        return new self($id, null, $failure);
    }
}
