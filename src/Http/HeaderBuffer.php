<?php

declare(strict_types=1);

namespace Expo\Push\Http;

/**
 * Collects the response headers of one cURL request.
 *
 * cURL calls the header function for every header block. A 1xx answer and a
 * proxy CONNECT answer each send their own block, so the buffer keeps only the
 * block of the final status line.
 */
final class HeaderBuffer
{
    /** @var array<string, list<string>> */
    public array $headers = [];

    public function startBlock(): void
    {
        $this->headers = [];
    }

    public function add(string $name, string $value): void
    {
        $this->headers[strtolower($name)][] = $value;
    }
}
