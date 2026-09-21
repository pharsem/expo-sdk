<?php

declare(strict_types=1);

namespace Expo\Push\Execution;

use Expo\Push\Http\CompletedRequest;
use Expo\Push\Http\HttpRequest;

/**
 * Starts requests and collects the answers.
 *
 * One scheduler drives every send. The dispatcher hides the difference between
 * a client that runs one request at a time and a client that runs several.
 */
interface Dispatcher
{
    public function start(int $id, HttpRequest $request): void;

    /**
     * @return list<CompletedRequest>
     */
    public function poll(int $timeoutMs): array;

    public function inFlight(): int;

    /**
     * The largest number of requests that this dispatcher holds at one time.
     */
    public function maxInFlight(): int;

    public function cancelAll(): void;
}
