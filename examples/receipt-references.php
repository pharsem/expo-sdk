<?php

declare(strict_types=1);

/**
 * Send now, store the receipt references, and read the receipts in another
 * process some minutes later.
 *
 * Run it with:
 *   php examples/receipt-references.php
 */

require __DIR__ . '/bootstrap.php';

use Expo\Push\Expo;
use Expo\Push\PushMessage;
use Expo\Push\Result\ReceiptReference;
use Expo\Push\Result\ReceiptState;

exampleHeading('process 1: send and store the references');

$sender = new OfflineTransport();
$sender->queue(['data' => [
    ['status' => 'ok', 'id' => 'receipt-1'],
    ['status' => 'ok', 'id' => 'receipt-2'],
    ['status' => 'ok', 'id' => 'receipt-3'],
]]);

$expo = new Expo(httpClient: $sender);
$result = $expo->send(PushMessage::to(exampleTokens(3))->title('Hi')->reference('order-42'));

$stored = array_map(
    static fn (ReceiptReference $reference): array => $reference->toStorageArray(),
    $result->receiptReferences()
);

$json = json_encode($stored, JSON_THROW_ON_ERROR);

printf("%d reference(s), %d byte(s) of JSON for your database\n", count($stored), strlen($json));
printf("Each one keeps the receipt ID, the device token, the input position and your reference.\n");

exampleHeading('wait');

printf("Read the receipts some minutes after the send. Expo keeps a receipt for a\n");
printf("limited time only, so a missing receipt can also mean an expired one.\n");

exampleHeading('process 2: read the receipts');

$decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

if (!is_array($decoded)) {
    exit(1);
}

$references = [];

foreach ($decoded as $entry) {
    if (is_array($entry)) {
        /** @var array<string, mixed> $entry */
        $references[] = ReceiptReference::fromStorageArray($entry);
    }
}

$reader = new OfflineTransport();
$reader->queue(['data' => [
    'receipt-1' => ['status' => 'ok'],
    'receipt-2' => ['status' => 'error', 'message' => 'not registered', 'details' => ['error' => 'DeviceNotRegistered']],
]]);

$lookup = (new Expo(httpClient: $reader))->receipts($references);

foreach ($lookup->entries() as $entry) {
    printf(
        "%-14s %-13s %s %s\n",
        $entry->id,
        $entry->state->value,
        $entry->token?->fingerprint() ?? '-',
        $entry->receipt?->errorCode ?? ''
    );
}

exampleHeading('the coverage of the lookup');

printf("returned: %s\n", json_encode($lookup->returnedIds()));
printf("missing:  %s  (not ready, invalid, or no longer kept)\n", json_encode($lookup->missingIds()));
printf("failed:   %s\n", json_encode($lookup->failedIds()));
printf("complete: %s\n", $lookup->isComplete() ? 'yes' : 'no');

foreach ($lookup->unregisteredTokens() as $dead) {
    printf("delete token %s\n", $dead->fingerprint());
}

exampleHeading('ask again for what is missing');

$second = new OfflineTransport();
$second->queue(['data' => ['receipt-3' => ['status' => 'ok']]]);

$later = (new Expo(httpClient: $second))->receipts(array_filter(
    $references,
    static fn (ReceiptReference $reference): bool => in_array($reference->id, $lookup->missingIds(), true)
));

$merged = $lookup->merge($later);

printf("after the merge: %d returned, %d missing\n", count($merged->returnedIds()), count($merged->missingIds()));
printf("the device token of receipt-3 survived the merge: %s\n", $merged->entry('receipt-3')?->token?->fingerprint() ?? '-');
printf("a returned receipt is never replaced by a missing one: %s\n",
    $merged->state('receipt-1') === ReceiptState::Returned ? 'yes' : 'no');

exampleHeading('three milestones');

printf("1. Expo accepted the notification.     A ticket with the status ok.\n");
printf("2. Apple or Google took it.            A receipt with the status ok.\n");
printf("3. The device showed it.               Nothing reports this.\n");
