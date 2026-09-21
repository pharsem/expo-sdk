<?php

declare(strict_types=1);

namespace Expo\Push\Http;

/**
 * A transport that can hold more than one request in flight.
 *
 * The SDK stays synchronous. The scheduler starts a bounded number of requests,
 * then polls until each one finishes. The transport never decides how many run:
 * `capabilities()->maxConcurrency` only says how many it supports.
 */
interface ConcurrentHttpClient extends HttpClient
{
    /**
     * Starts one request and returns at once.
     *
     * @param int $id the identifier that `poll()` must give back
     */
    public function start(int $id, HttpRequest $request): void;

    /**
     * Waits up to `$timeoutMs` for one or more requests to finish.
     *
     * The method must not busy loop. It must return an empty list when the time
     * runs out and nothing finished.
     *
     * @return list<CompletedRequest>
     */
    public function poll(int $timeoutMs): array;

    /**
     * The number of requests that are running now.
     */
    public function inFlight(): int;

    /**
     * Drops every running request and frees the handles.
     *
     * A request that the SDK drops after transmission has an unknown result. The
     * scheduler never calls this to make a result look clean.
     */
    public function cancelAll(): void;
}
