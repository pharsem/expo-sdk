<?php

declare(strict_types=1);

/**
 * A local benchmark of the planning, the chunking and the sending.
 *
 * Run it with:
 *   php benchmarks/run.php
 *   php benchmarks/run.php --quick
 *
 * The benchmark never talks to Expo. It uses an in process transport that
 * answers at once, so the numbers measure this SDK and nothing else. They say
 * nothing about the throughput of the real Expo service: the network and the
 * Expo rate limit decide that.
 */
require dirname(__DIR__) . '/vendor/autoload.php';

use Expo\Push\Expo;
use Expo\Push\Http\CompletedRequest;
use Expo\Push\Http\ConcurrentHttpClient;
use Expo\Push\Http\HttpRequest;
use Expo\Push\Http\HttpResponse;
use Expo\Push\Http\TransportCapabilities;
use Expo\Push\Plan\Planner;
use Expo\Push\PushMessage;

/**
 * A transport that answers every request at once with accepted tickets.
 */
final class BenchmarkTransport implements ConcurrentHttpClient
{
    /** @var array<int, HttpResponse> */
    private array $active = [];

    public int $requests = 0;

    public function capabilities(): TransportCapabilities
    {
        return new TransportCapabilities(6, true, true);
    }

    public function send(HttpRequest $request): HttpResponse
    {
        ++$this->requests;

        return self::answer($request);
    }

    public function start(int $id, HttpRequest $request): void
    {
        ++$this->requests;
        $this->active[$id] = self::answer($request);
    }

    /**
     * @return list<CompletedRequest>
     */
    public function poll(int $timeoutMs): array
    {
        $completed = [];

        foreach ($this->active as $id => $response) {
            $completed[] = CompletedRequest::response($id, $response);
        }

        $this->active = [];

        return $completed;
    }

    public function inFlight(): int
    {
        return count($this->active);
    }

    public function cancelAll(): void
    {
        $this->active = [];
    }

    private static function answer(HttpRequest $request): HttpResponse
    {
        $body = $request->body;

        if (($request->headers['content-encoding'] ?? null) === 'gzip') {
            $plain = gzdecode($body);
            $body = $plain === false ? $body : $plain;
        }

        $messages = json_decode($body, true);
        $count = 0;

        if (is_array($messages)) {
            foreach ($messages as $message) {
                $to = is_array($message) ? ($message['to'] ?? null) : null;
                $count += is_array($to) ? count($to) : 1;
            }
        }

        $data = [];

        for ($index = 0; $index < $count; ++$index) {
            $data[] = ['status' => 'ok', 'id' => 'r' . $index];
        }

        return new HttpResponse(200, (string) json_encode(['data' => $data]), [], strlen($request->body), 0);
    }
}

/**
 * @return list<string>
 */
function benchTokens(int $count): array
{
    $tokens = [];

    for ($index = 0; $index < $count; ++$index) {
        $tokens[] = sprintf('ExponentPushToken[%022d]', $index);
    }

    return $tokens;
}

/**
 * @param callable(): array{0: int, 1: string} $work
 *
 * @return array{label: string, items: int, ms: float, perSecond: float, memoryMb: float, note: string}
 */
function measure(string $label, callable $work): array
{
    gc_collect_cycles();
    $before = memory_get_usage(true);
    $peakBefore = memory_get_peak_usage(true);
    $start = hrtime(true);

    [$items, $note] = $work();

    $ms = (hrtime(true) - $start) / 1_000_000;
    $peak = max($peakBefore, memory_get_peak_usage(true)) - $before;

    return [
        'label' => $label,
        'items' => $items,
        'ms' => $ms,
        'perSecond' => $ms > 0 ? $items / ($ms / 1000) : 0.0,
        'memoryMb' => $peak / 1048576,
        'note' => $note,
    ];
}

$quick = in_array('--quick', $argv, true);
$large = $quick ? 5_000 : 50_000;
$send = $quick ? 2_000 : 20_000;

printf("Expo push SDK benchmark\n");
printf("PHP %s on %s, %s\n", PHP_VERSION, PHP_OS_FAMILY, php_uname('m'));
printf("zlib: %s, opcache: %s\n", extension_loaded('zlib') ? 'yes' : 'no', extension_loaded('Zend OPcache') ? 'yes' : 'no');
printf("memory_limit: %s\n", (string) ini_get('memory_limit'));
printf("mode: %s\n\n", $quick ? 'quick' : 'full');

$results = [];

$results[] = measure('plan one message with N devices', static function () use ($large): array {
    $plan = Planner::plan(PushMessage::to(benchTokens($large))->title('Broadcast'));

    return [$plan->count(), sprintf('%d payload(s) shared', count($plan->payloads))];
});

$results[] = measure('plan N messages of 100 devices', static function () use ($large): array {
    $messages = [];

    for ($index = 0; $index < intdiv($large, 100); ++$index) {
        $messages[] = PushMessage::to(benchTokens(100))->title('Batch ' . $index);
    }

    $plan = Planner::plan($messages);

    return [$plan->count(), sprintf('%d payload(s)', count($plan->payloads))];
});

$results[] = measure('chunk a plan into requests of 100', static function () use ($large): array {
    $plan = Planner::plan(PushMessage::to(benchTokens($large))->title('Broadcast'));
    $chunks = $plan->chunks(100);

    return [$plan->count(), sprintf('%d chunk(s)', count($chunks))];
});

$results[] = measure('lazy chunks, no whole plan in memory', static function () use ($large): array {
    $count = 0;

    foreach (Expo::lazyChunks(PushMessage::to(benchTokens($large)), 100) as $chunk) {
        foreach ($chunk as $message) {
            $count += $message->recipientCount();
        }
    }

    return [$count, 'streamed'];
});

$results[] = measure('sequential send', static function () use ($send): array {
    $transport = new BenchmarkTransport();
    $expo = new Expo(httpClient: $transport);
    $result = $expo->send(PushMessage::to(benchTokens($send))->title('Broadcast'));

    return [count($result->accepted()), sprintf('%d request(s)', $transport->requests)];
});

$results[] = measure('send with concurrency 6', static function () use ($send): array {
    $transport = new BenchmarkTransport();
    $expo = new Expo(httpClient: $transport, concurrency: 6);
    $result = $expo->send(PushMessage::to(benchTokens($send))->title('Broadcast'));

    return [count($result->accepted()), sprintf('%d request(s)', $transport->requests)];
});

$results[] = measure('build the result of a large send', static function () use ($send): array {
    $transport = new BenchmarkTransport();
    $expo = new Expo(httpClient: $transport);
    $result = $expo->send(PushMessage::to(benchTokens($send))->title('Broadcast'));

    return [count($result->receiptReferences()), sprintf('%d outcome(s)', $result->count())];
});

$results[] = measure('serialize the result for a queue', static function () use ($send): array {
    $transport = new BenchmarkTransport();
    $expo = new Expo(httpClient: $transport);
    $result = $expo->send(PushMessage::to(benchTokens($send))->title('Broadcast'));
    $json = json_encode($result->toStorageArray(), JSON_THROW_ON_ERROR);

    return [$result->count(), sprintf('%.1f MB of JSON', strlen($json) / 1048576)];
});

printf("%-40s %10s %12s %12s %10s  %s\n", 'step', 'items', 'ms', 'items/s', 'peak MB', 'note');
printf("%s\n", str_repeat('-', 110));

foreach ($results as $row) {
    printf(
        "%-40s %10d %12.1f %12.0f %10.1f  %s\n",
        $row['label'],
        $row['items'],
        $row['ms'],
        $row['perSecond'],
        $row['memoryMb'],
        $row['note']
    );
}

printf("\nThe transport answers in this process. Real Expo throughput is bounded by\n");
printf("the network and by the documented limit of 600 notifications each second.\n");
