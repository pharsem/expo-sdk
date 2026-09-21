<?php

declare(strict_types=1);

namespace Expo\Push\Execution;

use Expo\Push\Exception\TransportException;
use Expo\Push\Http\CompletedRequest;
use Expo\Push\Http\ConcurrentHttpClient;
use Expo\Push\Http\HttpRequest;

/**
 * Runs up to `$concurrency` requests at a time through a concurrent transport.
 *
 * A transport can refuse a request before it starts, for example when the URL
 * scheme or a header is wrong. The dispatcher turns that failure into a
 * completed request, exactly like the sequential path, so `send()` returns a
 * result and never raises for an operational failure.
 */
final class ConcurrentDispatcher implements Dispatcher
{
    /** @var list<CompletedRequest> */
    private array $finished = [];

    public function __construct(
        private readonly ConcurrentHttpClient $client,
        private readonly int $concurrency,
    ) {
    }

    #[\Override]
    public function start(int $id, HttpRequest $request): void
    {
        try {
            $this->client->start($id, $request);
        } catch (TransportException $exception) {
            $this->finished[] = CompletedRequest::failure($id, $exception->failure);
        }
    }

    #[\Override]
    public function poll(int $timeoutMs): array
    {
        $queued = $this->finished;
        $this->finished = [];

        // A queued failure needs no waiting, so the poll returns at once.
        return [...$queued, ...$this->client->poll($queued === [] ? $timeoutMs : 0)];
    }

    #[\Override]
    public function inFlight(): int
    {
        return $this->client->inFlight() + count($this->finished);
    }

    #[\Override]
    public function maxInFlight(): int
    {
        return $this->concurrency;
    }

    #[\Override]
    public function cancelAll(): void
    {
        $this->finished = [];
        $this->client->cancelAll();
    }
}
