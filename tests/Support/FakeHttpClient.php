<?php

declare(strict_types=1);

namespace Expo\Push\Tests\Support;

use Expo\Push\Http\HttpClient;
use Expo\Push\Http\HttpResponse;
use RuntimeException;

/**
 * An HTTP client that answers from a queue and records every request.
 */
final class FakeHttpClient implements HttpClient
{
    /** @var list<HttpResponse> */
    private array $responses = [];

    /** @var list<array{url: string, body: string, headers: array<string, string>}> */
    public array $requests = [];

    /**
     * @param array<string, mixed>              $body
     * @param array<string, string|list<string>> $headers
     */
    public function queue(array $body, int $status = 200, array $headers = []): self
    {
        $this->responses[] = new HttpResponse($status, (string) json_encode($body), $headers);

        return $this;
    }

    /**
     * @param array<string, string|list<string>> $headers
     */
    public function queueRaw(string $body, int $status = 200, array $headers = []): self
    {
        $this->responses[] = new HttpResponse($status, $body, $headers);

        return $this;
    }

    public function post(string $url, string $body, array $headers): HttpResponse
    {
        if (isset($headers['content-encoding']) && $headers['content-encoding'] === 'gzip') {
            $decoded = gzdecode($body);
            $body = $decoded === false ? $body : $decoded;
        }

        $this->requests[] = ['url' => $url, 'body' => $body, 'headers' => $headers];

        $response = array_shift($this->responses);

        if ($response === null) {
            throw new RuntimeException('The fake client has no answer left for ' . $url);
        }

        return $response;
    }

    public function requestCount(): int
    {
        return count($this->requests);
    }

    /**
     * @return array<array-key, mixed>
     */
    public function payload(int $index = 0): array
    {
        $body = json_decode($this->requests[$index]['body'], true);

        if (!is_array($body)) {
            throw new RuntimeException('Request ' . $index . ' does not hold a JSON array.');
        }

        return $body;
    }

    /**
     * @return array<string, string>
     */
    public function headers(int $index = 0): array
    {
        return $this->requests[$index]['headers'];
    }
}
