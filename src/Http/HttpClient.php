<?php

declare(strict_types=1);

namespace Expo\Push\Http;

use Expo\Push\Exception\TransportException;

/**
 * Sends one HTTP request and returns the answer.
 *
 * Write your own class with this interface to use another HTTP library, or to
 * record the requests in a test.
 *
 * Rules for a transport:
 *
 * - Return every status code, including 4xx and 5xx. Never raise for a status.
 * - Raise `TransportException` only when there is no response at all.
 * - Never repeat a request. The retry engine of the SDK owns every attempt.
 * - Report the capabilities honestly. The SDK validates the settings against them.
 */
interface HttpClient
{
    /**
     * @throws TransportException when the attempt produced no response
     */
    public function send(HttpRequest $request): HttpResponse;

    /**
     * What this transport supports. The SDK reads it one time, at construction.
     */
    public function capabilities(): TransportCapabilities;
}
