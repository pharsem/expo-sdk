<?php

declare(strict_types=1);

/**
 * The smallest send, and what the result tells you.
 *
 * Run it with:
 *   php examples/minimal.php
 *   EXPO_LIVE=1 php examples/minimal.php "ExponentPushToken[xxxxxxxxxxxxxxxxxxxxxx]"
 */

require __DIR__ . '/bootstrap.php';

use Expo\Push\Expo;
use Expo\Push\Http\CurlHttpClient;

$transport = exampleIsLive() ? new CurlHttpClient() : new OfflineTransport();

$expo = new Expo(
    accessToken: getenv('EXPO_ACCESS_TOKEN') ?: null,
    httpClient: $transport,
);

exampleHeading('one notification');

$result = $expo->notify(
    to: exampleToken(),
    title: 'Your order is on the way',
    body: 'It arrives before 18:00.',
    data: ['orderId' => 42],
    reference: 'order-42',
);

foreach ($result->outcomes() as $outcome) {
    printf(
        "%-13s %s  receipt=%s  duplicateRisk=%s\n",
        $outcome->acceptance->value,
        $outcome->token->fingerprint(),
        $outcome->receiptId() ?? '-',
        $outcome->duplicateRisk ? 'yes' : 'no'
    );
}

printf("\ncomplete success: %s\n", $result->isCompleteSuccess() ? 'yes' : 'no');
printf("needs attention:  %s\n", $result->needsAttention() ? 'yes' : 'no');

// Store these and read the receipts some minutes later. A ticket says only that
// Expo accepted the notification, never that Apple, Google or the device took it.
printf("\nreceipt references to store: %d\n", count($result->receiptReferences()));

// The result never raises for a device error. Ask for the request level result.
$result->throwIfRequestFailed();
