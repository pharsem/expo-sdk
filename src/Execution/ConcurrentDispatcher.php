<?php

declare(strict_types=1);

namespace Expo\Push\Execution;

use Expo\Push\Http\ConcurrentHttpClient;
use Expo\Push\Http\HttpRequest;

/**
 * Runs up to `$concurrency` requests at a time through a concurrent transport.
 */
final class ConcurrentDispatcher implements Dispatcher
{
    public function __construct(
        private readonly ConcurrentHttpClient $client,
        private readonly int $concurrency,
    ) {
    }

    #[\Override]
    public function start(int $id, HttpRequest $request): void
    {
        $this->client->start($id, $request);
    }

    #[\Override]
    public function poll(int $timeoutMs): array
    {
        return $this->client->poll($timeoutMs);
    }

    #[\Override]
    public function inFlight(): int
    {
        return $this->client->inFlight();
    }

    #[\Override]
    public function maxInFlight(): int
    {
        return $this->concurrency;
    }

    #[\Override]
    public function cancelAll(): void
    {
        $this->client->cancelAll();
    }
}
