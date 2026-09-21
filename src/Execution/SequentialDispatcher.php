<?php

declare(strict_types=1);

namespace Expo\Push\Execution;

use Expo\Push\Exception\TransportException;
use Expo\Push\Http\CompletedRequest;
use Expo\Push\Http\HttpClient;
use Expo\Push\Http\HttpRequest;

/**
 * Runs one request at a time through a plain `HttpClient`.
 *
 * `start()` sends the request and keeps the answer. `poll()` gives it back. The
 * scheduler code stays the same for one request and for six.
 */
final class SequentialDispatcher implements Dispatcher
{
    /** @var list<CompletedRequest> */
    private array $finished = [];

    public function __construct(private readonly HttpClient $client)
    {
    }

    #[\Override]
    public function start(int $id, HttpRequest $request): void
    {
        try {
            $this->finished[] = CompletedRequest::response($id, $this->client->send($request));
        } catch (TransportException $exception) {
            $this->finished[] = CompletedRequest::failure($id, $exception->failure);
        }
    }

    #[\Override]
    public function poll(int $timeoutMs): array
    {
        $completed = $this->finished;
        $this->finished = [];

        return $completed;
    }

    #[\Override]
    public function inFlight(): int
    {
        return count($this->finished);
    }

    #[\Override]
    public function maxInFlight(): int
    {
        return 1;
    }

    #[\Override]
    public function cancelAll(): void
    {
        $this->finished = [];
    }
}
