<?php

declare(strict_types=1);

namespace Expo\Push\Tests\Support;

use Expo\Push\Exception\TransportException;
use Expo\Push\Http\HttpClient;
use Expo\Push\Http\HttpRequest;
use Expo\Push\Http\HttpResponse;
use Expo\Push\Http\TransportCapabilities;
use Expo\Push\Http\TransportFailure;
use Expo\Push\Http\TransportFailureKind;
use RuntimeException;

/**
 * A transport that answers from a queue and records every request.
 */
final class FakeHttpClient implements HttpClient
{
    /** @var list<HttpResponse|TransportFailure> */
    private array $steps = [];

    /** @var list<HttpRequest> */
    public array $requests = [];

    public function __construct(private readonly TransportCapabilities $capabilities = new TransportCapabilities())
    {
    }

    /**
     * @param array<string, mixed>               $body
     * @param array<string, string|list<string>> $headers
     */
    public function queue(array $body, int $status = 200, array $headers = []): self
    {
        return $this->queueRaw((string) json_encode($body), $status, $headers);
    }

    /**
     * @param array<string, string|list<string>> $headers
     */
    public function queueRaw(string $body, int $status = 200, array $headers = []): self
    {
        $this->steps[] = new HttpResponse($status, $body, $headers, null, 1);

        return $this;
    }

    public function queueFailure(TransportFailureKind $kind, string $message = 'fake failure'): self
    {
        $this->steps[] = TransportFailure::of($kind, $message, $kind->value);

        return $this;
    }

    #[\Override]
    public function capabilities(): TransportCapabilities
    {
        return $this->capabilities;
    }

    #[\Override]
    public function send(HttpRequest $request): HttpResponse
    {
        $this->requests[] = $request;

        $step = array_shift($this->steps);

        if ($step === null) {
            throw new RuntimeException(sprintf(
                'The fake transport has no answer left for %s (request %d).',
                $request->url,
                count($this->requests)
            ));
        }

        if ($step instanceof TransportFailure) {
            throw new TransportException($step);
        }

        return $step;
    }

    public function requestCount(): int
    {
        return count($this->requests);
    }

    /**
     * The decoded body of one request, with the gzip removed when the SDK used it.
     *
     * @return array<array-key, mixed>
     */
    public function payload(int $index = 0): array
    {
        $request = $this->requests[$index] ?? null;

        if ($request === null) {
            throw new RuntimeException('There is no request at index ' . $index . '.');
        }

        $body = $request->body;

        if (($request->headers['content-encoding'] ?? null) === 'gzip') {
            $plain = gzdecode($body);
            $body = $plain === false ? $body : $plain;
        }

        $decoded = json_decode($body, true);

        if (!is_array($decoded)) {
            throw new RuntimeException('Request ' . $index . ' does not hold a JSON array.');
        }

        return $decoded;
    }

    /**
     * @return array<string, string>
     */
    public function headers(int $index = 0): array
    {
        $request = $this->requests[$index] ?? null;

        if ($request === null) {
            throw new RuntimeException('There is no request at index ' . $index . '.');
        }

        return $request->headers;
    }

    public function pending(): int
    {
        return count($this->steps);
    }
}
