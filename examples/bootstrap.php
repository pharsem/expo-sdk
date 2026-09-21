<?php

declare(strict_types=1);

/**
 * The shared setup of every example.
 *
 * Every example runs offline by default: it answers from a small in process
 * transport, so you can read the output without an Expo account and without
 * sending a notification to a real device.
 *
 * Set EXPO_LIVE=1 and EXPO_ACCESS_TOKEN to talk to the real service. Then give a
 * real push token on the command line.
 */
require dirname(__DIR__) . '/vendor/autoload.php';

use Expo\Push\Expo;
use Expo\Push\Http\HttpClient;
use Expo\Push\Http\HttpRequest;
use Expo\Push\Http\HttpResponse;
use Expo\Push\Http\TransportCapabilities;
use Expo\Push\Http\TransportFailure;
use Expo\Push\Http\TransportFailureKind;
use Expo\Push\Exception\TransportException;

/**
 * Answers from a script, so an example runs without a network.
 */
final class OfflineTransport implements HttpClient
{
    /** @var list<HttpResponse|TransportFailure> */
    private array $steps = [];

    /** @var list<HttpRequest> */
    public array $requests = [];

    /**
     * @param array<string, mixed> $body
     */
    public function queue(array $body, int $status = 200): self
    {
        $this->steps[] = new HttpResponse($status, (string) json_encode($body), [], null, 1);

        return $this;
    }

    public function queueFailure(TransportFailureKind $kind, string $message = 'the network failed'): self
    {
        $this->steps[] = TransportFailure::of($kind, $message, $kind->value);

        return $this;
    }

    public function capabilities(): TransportCapabilities
    {
        return new TransportCapabilities();
    }

    public function send(HttpRequest $request): HttpResponse
    {
        $this->requests[] = $request;
        $step = array_shift($this->steps);

        if ($step === null) {
            // Nothing left in the script: answer with accepted tickets.
            return new HttpResponse(200, (string) json_encode(['data' => self::tickets($request)]), [], null, 1);
        }

        if ($step instanceof TransportFailure) {
            throw new TransportException($step);
        }

        return $step;
    }

    /**
     * @return list<array{status: string, id: string}>
     */
    private static function tickets(HttpRequest $request): array
    {
        $messages = json_decode($request->body, true);
        $tickets = [];

        if (is_array($messages)) {
            foreach ($messages as $message) {
                $to = is_array($message) ? ($message['to'] ?? null) : null;
                $count = is_array($to) ? count($to) : 1;

                for ($index = 0; $index < $count; ++$index) {
                    $tickets[] = ['status' => 'ok', 'id' => 'offline-' . count($tickets)];
                }
            }
        }

        return $tickets;
    }
}

function exampleIsLive(): bool
{
    return getenv('EXPO_LIVE') === '1';
}

/**
 * The token to send to. Offline it is a fixed valid looking value.
 */
function exampleToken(int $position = 1): string
{
    global $argv;

    if (exampleIsLive() && isset($argv[$position])) {
        return $argv[$position];
    }

    return sprintf('ExponentPushToken[%022d]', $position);
}

/**
 * @return list<string>
 */
function exampleTokens(int $count): array
{
    $tokens = [];

    for ($index = 0; $index < $count; ++$index) {
        $tokens[] = sprintf('ExponentPushToken[%022d]', $index);
    }

    return $tokens;
}

function exampleHeading(string $title): void
{
    printf("\n== %s ==\n\n", $title);
}
