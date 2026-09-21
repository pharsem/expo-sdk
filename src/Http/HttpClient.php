<?php

declare(strict_types=1);

namespace Expo\Push\Http;

use Expo\Push\Exception\TransportException;

/**
 * Sends one HTTP POST request.
 *
 * Write your own class with this interface to use another HTTP library, or to
 * record the requests in a test.
 */
interface HttpClient
{
    /**
     * @param array<string, string> $headers
     *
     * @throws TransportException when the request does not reach the server
     */
    public function post(string $url, string $body, array $headers): HttpResponse;
}
