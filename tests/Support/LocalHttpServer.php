<?php

declare(strict_types=1);

namespace Expo\Push\Tests\Support;

use RuntimeException;

/**
 * Starts the tiny HTTP server of `server.php` in another process.
 *
 * The tests talk to 127.0.0.1 only. Nothing reaches Expo and no notification
 * goes out.
 */
final class LocalHttpServer
{
    /** @var resource|null */
    private mixed $process = null;

    /** @var array<int, resource> */
    private array $pipes = [];

    private function __construct(public readonly int $port)
    {
    }

    public static function start(): self
    {
        $port = self::freePort();
        $server = new self($port);
        $script = __DIR__ . DIRECTORY_SEPARATOR . 'server.php';

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open(
            [PHP_BINARY, $script, (string) $port],
            $descriptors,
            $pipes
        );

        if (!is_resource($process)) {
            throw new RuntimeException('The test server did not start.');
        }

        $server->process = $process;
        $server->pipes = $pipes;

        stream_set_blocking($pipes[1], false);

        $deadline = microtime(true) + 10.0;

        while (microtime(true) < $deadline) {
            $line = fgets($pipes[1]);

            if (is_string($line) && trim($line) === 'ready') {
                return $server;
            }

            usleep(20_000);
        }

        $server->stop();

        throw new RuntimeException('The test server did not report that it is ready.');
    }

    public function url(string $path = ''): string
    {
        return sprintf('http://127.0.0.1:%d%s', $this->port, $path);
    }

    /**
     * @return array{connections: int, requests: int}
     */
    public function stats(): array
    {
        $body = file_get_contents($this->url('/stats'));

        if ($body === false) {
            throw new RuntimeException('The test server did not answer the stats request.');
        }

        $decoded = json_decode($body, true);

        if (!is_array($decoded) || !is_int($decoded['connections'] ?? null) || !is_int($decoded['requests'] ?? null)) {
            throw new RuntimeException('The test server sent invalid stats.');
        }

        return ['connections' => $decoded['connections'], 'requests' => $decoded['requests']];
    }

    public function stop(): void
    {
        foreach ($this->pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }

        $this->pipes = [];

        if (is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
        }

        $this->process = null;
    }

    private static function freePort(): int
    {
        $socket = @stream_socket_server('tcp://127.0.0.1:0', $errno, $error);

        if ($socket === false) {
            throw new RuntimeException('The test could not reserve a port: ' . $error);
        }

        $name = stream_socket_get_name($socket, false);
        fclose($socket);

        if (!is_string($name)) {
            throw new RuntimeException('The test could not read the reserved port.');
        }

        $parts = explode(':', $name);

        return (int) $parts[count($parts) - 1];
    }
}
