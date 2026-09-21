<?php

declare(strict_types=1);

/**
 * A tiny HTTP server for the integration tests.
 *
 * Run it with:
 *   php tests/Support/server.php <port>
 *
 * It speaks raw HTTP, so a test can ask for a repeated header, an interim 1xx
 * block, a gzip body or a broken body. It keeps the connection open, so a test
 * can prove that the SDK reuses it.
 *
 * The server never talks to Expo and never sends a notification.
 */
$port = (int) ($argv[1] ?? 0);

if ($port < 1) {
    fwrite(STDERR, "Give a port as the first argument.\n");

    exit(1);
}

$listen = @stream_socket_server('tcp://127.0.0.1:' . $port, $errno, $error);

if ($listen === false) {
    fwrite(STDERR, sprintf("The server could not listen on port %d: %s\n", $port, $error));

    exit(1);
}

stream_set_blocking($listen, false);

fwrite(STDOUT, "ready\n");
flush();

/** @var array<int, resource> $clients */
$clients = [];
$connections = 0;
$requests = 0;
$startedAt = time();

/**
 * @return array{path: string, body: string, close: bool}|null
 */
function readRequest(mixed $client): ?array
{
    $head = '';

    while (!str_contains($head, "\r\n\r\n")) {
        $chunk = fread($client, 1);

        if ($chunk === false || $chunk === '') {
            return null;
        }

        $head .= $chunk;

        if (strlen($head) > 16384) {
            return null;
        }
    }

    $lines = explode("\r\n", trim($head));
    $first = explode(' ', $lines[0]);
    $path = $first[1] ?? '/';
    $length = 0;
    $close = false;

    foreach ($lines as $line) {
        if (stripos($line, 'content-length:') === 0) {
            $length = (int) trim(substr($line, 15));
        }

        if (stripos($line, 'connection:') === 0 && stripos($line, 'close') !== false) {
            $close = true;
        }
    }

    $body = '';

    while (strlen($body) < $length) {
        $chunk = fread($client, max(1, $length - strlen($body)));

        if ($chunk === false || $chunk === '') {
            break;
        }

        $body .= $chunk;
    }

    return ['path' => $path, 'body' => $body, 'close' => $close];
}

function respond(string $path, int $connections, int $requests): string
{
    $json = '{"data":[{"status":"ok","id":"r1"}]}';

    return match (true) {
        str_starts_with($path, '/stats') => body(
            200,
            (string) json_encode(['connections' => $connections, 'requests' => $requests]),
            ['content-type: application/json']
        ),
        str_starts_with($path, '/repeat-headers') => body(200, $json, [
            'content-type: application/json',
            'x-note: first',
            'x-note: second',
            'Retry-After: 3',
        ]),
        str_starts_with($path, '/interim') => "HTTP/1.1 100 Continue\r\nx-interim: yes\r\n\r\n"
            . body(200, $json, ['content-type: application/json', 'x-final: yes']),
        str_starts_with($path, '/gzip') => body(
            200,
            (string) gzencode($json),
            ['content-type: application/json', 'content-encoding: gzip']
        ),
        str_starts_with($path, '/not-json') => body(200, '<html>not json</html>', ['content-type: text/html']),
        str_starts_with($path, '/status-429') => body(429, '{"errors":[{"code":"TOO_MANY_REQUESTS"}]}', [
            'content-type: application/json',
            'retry-after: 2',
        ]),
        str_starts_with($path, '/status-500') => body(500, 'server error', ['content-type: text/plain']),
        str_starts_with($path, '/redirect') => body(302, '', ['location: https://example.test/elsewhere']),
        str_starts_with($path, '/echo') => body(200, $json, ['content-type: application/json']),
        default => body(200, $json, ['content-type: application/json']),
    };
}

/**
 * @param list<string> $headers
 */
function body(int $status, string $payload, array $headers = []): string
{
    $lines = array_merge($headers, ['content-length: ' . strlen($payload), 'connection: keep-alive']);

    return sprintf("HTTP/1.1 %d OK\r\n%s\r\n\r\n%s", $status, implode("\r\n", $lines), $payload);
}

while (true) {
    if (time() - $startedAt > 60) {
        break;
    }

    $read = $clients;
    $read[] = $listen;
    $write = null;
    $except = null;

    if (@stream_select($read, $write, $except, 0, 200_000) === false) {
        continue;
    }

    foreach ($read as $socket) {
        if ($socket === $listen) {
            $client = @stream_socket_accept($listen, 0);

            if ($client !== false) {
                stream_set_blocking($client, true);
                stream_set_timeout($client, 5);
                $clients[(int) $client] = $client;
                ++$connections;
            }

            continue;
        }

        $request = readRequest($socket);

        if ($request === null) {
            unset($clients[(int) $socket]);
            fclose($socket);

            continue;
        }

        ++$requests;

        if (str_starts_with($request['path'], '/truncate')) {
            // Send a shorter body than the header promises, then hang up.
            fwrite($socket, "HTTP/1.1 200 OK\r\ncontent-length: 500\r\n\r\nshort");
            unset($clients[(int) $socket]);
            fclose($socket);

            continue;
        }

        fwrite($socket, respond($request['path'], $connections, $requests));

        if ($request['close']) {
            unset($clients[(int) $socket]);
            fclose($socket);
        }
    }
}

fclose($listen);
