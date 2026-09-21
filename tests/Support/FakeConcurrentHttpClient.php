<?php

declare(strict_types=1);

namespace Expo\Push\Tests\Support;

use Expo\Push\Exception\TransportException;
use Expo\Push\Http\CompletedRequest;
use Expo\Push\Http\ConcurrentHttpClient;
use Expo\Push\Http\HttpRequest;
use Expo\Push\Http\HttpResponse;
use Expo\Push\Http\TransportCapabilities;
use Expo\Push\Http\TransportFailure;
use Expo\Push\Http\TransportFailureKind;
use RuntimeException;

/**
 * A concurrent transport for a test.
 *
 * Each queued step carries a delay in "polls". A step with delay 2 finishes on
 * the second poll after its start, so a test can force an out of order finish.
 */
final class FakeConcurrentHttpClient implements ConcurrentHttpClient
{
    /** @var list<array{result: HttpResponse|TransportFailure, polls: int}> */
    private array $steps = [];

    /** @var array<int, array{result: HttpResponse|TransportFailure, polls: int, request: HttpRequest}> */
    private array $active = [];

    /** @var list<HttpRequest> */
    public array $requests = [];

    /** @var list<int> */
    public array $inFlightHistory = [];

    /** @var list<int> */
    public array $startOrder = [];

    /** @var list<int> */
    public array $finishOrder = [];

    public int $polls = 0;

    /**
     * @param TransportFailureKind|null $refuseStart makes start() raise, the way a
     *                                               real transport does for a bad
     *                                               URL or a bad header
     */
    public function __construct(
        private readonly int $maxConcurrency = 6,
        private readonly ?TransportFailureKind $refuseStart = null,
    ) {
    }

    /**
     * @param array<string, mixed>               $body
     * @param array<string, string|list<string>> $headers
     */
    public function queue(array $body, int $status = 200, int $polls = 1, array $headers = []): self
    {
        $this->steps[] = [
            'result' => new HttpResponse($status, (string) json_encode($body), $headers, null, 1),
            'polls' => max(1, $polls),
        ];

        return $this;
    }

    public function queueRaw(string $body, int $status = 200, int $polls = 1): self
    {
        $this->steps[] = ['result' => new HttpResponse($status, $body, [], null, 1), 'polls' => max(1, $polls)];

        return $this;
    }

    public function queueFailure(TransportFailureKind $kind, string $message = 'fake failure', int $polls = 1): self
    {
        $this->steps[] = ['result' => TransportFailure::of($kind, $message, $kind->value), 'polls' => max(1, $polls)];

        return $this;
    }

    #[\Override]
    public function capabilities(): TransportCapabilities
    {
        return new TransportCapabilities($this->maxConcurrency, true, true);
    }

    #[\Override]
    public function send(HttpRequest $request): HttpResponse
    {
        $this->requests[] = $request;
        $step = array_shift($this->steps);

        if ($step === null) {
            throw new RuntimeException('The fake transport has no answer left for ' . $request->url . '.');
        }

        if ($step['result'] instanceof TransportFailure) {
            throw new TransportException($step['result']);
        }

        return $step['result'];
    }

    #[\Override]
    public function start(int $id, HttpRequest $request): void
    {
        if ($this->refuseStart !== null) {
            $this->requests[] = $request;

            throw new TransportException(TransportFailure::of(
                $this->refuseStart,
                'the transport refused the request',
                $this->refuseStart->value
            ));
        }

        $step = array_shift($this->steps);

        if ($step === null) {
            throw new RuntimeException('The fake transport has no answer left for ' . $request->url . '.');
        }

        $this->requests[] = $request;
        $this->startOrder[] = $id;
        $this->active[$id] = ['result' => $step['result'], 'polls' => $step['polls'], 'request' => $request];
        $this->inFlightHistory[] = count($this->active);
    }

    #[\Override]
    public function poll(int $timeoutMs): array
    {
        ++$this->polls;
        $completed = [];

        foreach ($this->active as $id => $entry) {
            $entry['polls'] -= 1;

            if ($entry['polls'] > 0) {
                $this->active[$id] = $entry;

                continue;
            }

            unset($this->active[$id]);
            $this->finishOrder[] = $id;

            $completed[] = $entry['result'] instanceof TransportFailure
                ? CompletedRequest::failure($id, $entry['result'])
                : CompletedRequest::response($id, $entry['result']);
        }

        return $completed;
    }

    #[\Override]
    public function inFlight(): int
    {
        return count($this->active);
    }

    #[\Override]
    public function cancelAll(): void
    {
        $this->active = [];
    }

    public function requestCount(): int
    {
        return count($this->requests);
    }

    public function maxObservedInFlight(): int
    {
        return $this->inFlightHistory === [] ? 0 : max($this->inFlightHistory);
    }

    /**
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
}
