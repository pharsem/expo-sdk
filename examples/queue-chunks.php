<?php

declare(strict_types=1);

/**
 * One queue job for each chunk.
 *
 * `Expo::chunk()` splits the work without sending. Store each chunk with
 * `PushMessage::toStorageArray()`, put it on your queue, and send it in the
 * worker.
 *
 * There is no whole operation check here: a broken message in a later chunk
 * shows up only when that chunk runs. `Expo::send()` checks everything first.
 *
 * Run it with:
 *   php examples/queue-chunks.php
 */

require __DIR__ . '/bootstrap.php';

use Expo\Push\Expo;
use Expo\Push\PushMessage;

exampleHeading('split 250 devices into queue jobs');

$message = PushMessage::to(exampleTokens(250))->title('New release')->ttl(3600)->reference('release-2.0');

$jobs = [];

foreach (Expo::chunk($message) as $number => $chunk) {
    // One job payload. It is plain JSON, so any queue can carry it.
    $jobs[$number] = json_encode(
        array_map(static fn (PushMessage $item): array => $item->toStorageArray(), $chunk),
        JSON_THROW_ON_ERROR
    );

    printf("job %d: %d byte(s), %d notification(s)\n", $number, strlen($jobs[$number]), array_sum(array_map(
        static fn (PushMessage $item): int => $item->recipientCount(),
        $chunk
    )));
}

exampleHeading('the worker reads one job and sends it');

$expo = new Expo(httpClient: new OfflineTransport());

$payload = json_decode($jobs[0], true, 512, JSON_THROW_ON_ERROR);

if (!is_array($payload)) {
    exit(1);
}

$messages = [];

foreach ($payload as $stored) {
    if (is_array($stored)) {
        /** @var array<string, mixed> $stored */
        $messages[] = PushMessage::fromStorageArray($stored);
    }
}

$result = $expo->send($messages);

printf("accepted: %d of %d\n", count($result->accepted()), $result->count());
printf("reference kept: %s\n", $result->outcomes()[0]->reference ?? '-');

exampleHeading('a very large input: stream the chunks');

// lazyChunks() reads your input as it goes, so nothing holds the whole plan.
$count = 0;

foreach (Expo::lazyChunks(PushMessage::to(exampleTokens(1000)), 100) as $chunk) {
    $count += count($chunk);
}

printf("%d chunk group(s) streamed, peak memory %.1f MB\n", $count, memory_get_peak_usage(true) / 1048576);

exampleHeading('what your queue must not do');

printf("Do not let the queue replay a chunk that already produced tickets.\n");
printf("Store the SendResult, and schedule only notAttempted() and unknown().\n");
printf("An unknown notification can already be on its way: a resend can duplicate it.\n");
