<?php

declare(strict_types=1);

/**
 * A bridge from the observer events to a logger and a metrics collector.
 *
 * Every field of every event is safe to log. No device token, no message body,
 * no custom data, no authorization value and no raw response body reaches an
 * event.
 *
 * A storage array is the opposite. It holds your application data on purpose,
 * so never send one to a logger.
 *
 * Run it with:
 *   php examples/observability.php
 */

require __DIR__ . '/bootstrap.php';

use Expo\Push\Expo;
use Expo\Push\Http\TransportFailureKind;
use Expo\Push\Observability\AttemptFinished;
use Expo\Push\Observability\ChunkDeferred;
use Expo\Push\Observability\ChunkFinished;
use Expo\Push\Observability\Event;
use Expo\Push\Observability\Observer;
use Expo\Push\Observability\OperationFinished;
use Expo\Push\Observability\WaitScheduled;
use Expo\Push\PushMessage;

/**
 * Writes one structured line for each event, and counts a few metrics.
 */
final class LoggingObserver implements Observer
{
    /** @var array<string, int> */
    public array $metrics = [];

    public function onEvent(Event $event): void
    {
        // Every value here is a scalar or null, and every one is safe to log.
        $fields = [];

        foreach ($event->fields() as $key => $value) {
            $fields[] = sprintf('%s=%s', $key, var_export($value, true));
        }

        printf("%-18s %s\n", $event->name(), implode(' ', $fields));

        $this->count($event);
    }

    private function count(Event $event): void
    {
        $key = match (true) {
            $event instanceof AttemptFinished => 'expo.attempt.' . $event->record->result->value,
            $event instanceof WaitScheduled => 'expo.wait.' . $event->reason->value,
            $event instanceof ChunkDeferred => 'expo.chunk.deferred',
            $event instanceof ChunkFinished => 'expo.chunk.' . ($event->succeeded ? 'ok' : 'failed'),
            $event instanceof OperationFinished => 'expo.operation.finished',
            default => null,
        };

        if ($key !== null) {
            $this->metrics[$key] = ($this->metrics[$key] ?? 0) + 1;
        }
    }
}

exampleHeading('the events of one send with a retry');

$transport = new OfflineTransport();
$transport->queueFailure(TransportFailureKind::Timeout, 'the answer did not arrive');
$transport->queue(['data' => [['status' => 'ok', 'id' => 'receipt-1']]]);

$observer = new LoggingObserver();

$expo = new Expo(
    httpClient: $transport,
    observer: $observer,
);

$result = $expo->send(
    PushMessage::to(exampleToken())
        ->title('Secret title')
        ->body('Secret body')
        ->data(['pin' => '1234'])
);

exampleHeading('the metrics');

foreach ($observer->metrics as $key => $count) {
    printf("%-30s %d\n", $key, $count);
}

exampleHeading('nothing sensitive reached the log');

printf("accepted: %d, duplicate risk: %d\n", count($result->accepted()), count($result->duplicateRisk()));
printf("The lines above hold no token, no title, no body and no custom data.\n");

exampleHeading('an observer cannot break a send');

final class BrokenObserver implements Observer
{
    public function onEvent(Event $event): void
    {
        throw new RuntimeException('the metrics service is down');
    }
}

$safe = new Expo(httpClient: new OfflineTransport(), observer: new BrokenObserver());
$survived = $safe->send(PushMessage::to(exampleToken())->title('Hi'));

printf("accepted: %d\n", count($survived->accepted()));
printf("The SDK isolates every observer call, counts the failures and stops calling\n");
printf("an observer that fails again and again.\n");
