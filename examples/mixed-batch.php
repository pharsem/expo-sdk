<?php

declare(strict_types=1);

/**
 * A batch where some devices work, one is gone, one request fails and one chunk
 * never starts. Every state stays readable.
 *
 * Run it with:
 *   php examples/mixed-batch.php
 */

require __DIR__ . '/bootstrap.php';

use Expo\Push\Expo;
use Expo\Push\Http\TransportFailureKind;
use Expo\Push\PushError;
use Expo\Push\PushMessage;
use Expo\Push\Result\Acceptance;
use Expo\Push\Retry\NoRetryPolicy;

$transport = new OfflineTransport();

// Chunk 1: one accepted device and one dead token.
$transport->queue(['data' => [
    ['status' => 'ok', 'id' => 'receipt-1'],
    ['status' => 'error', 'message' => 'not registered', 'details' => ['error' => 'DeviceNotRegistered']],
]]);

// Chunk 2: a timeout after the request left this process.
$transport->queueFailure(TransportFailureKind::Timeout, 'the answer did not arrive');

$expo = new Expo(
    httpClient: $transport,
    retryPolicy: new NoRetryPolicy(),
    sendChunkSize: 2,
);

exampleHeading('a batch of six notifications in chunks of two');

$result = $expo->send(PushMessage::to(exampleTokens(6))->title('New release')->reference('release-2.0'));

foreach ($result->outcomes() as $outcome) {
    printf(
        "#%d %-13s %-22s %s\n",
        $outcome->index,
        $outcome->acceptance->value,
        $outcome->reason?->value ?? ($outcome->receiptId() ?? '-'),
        $outcome->duplicateRisk ? 'duplicate risk' : ''
    );
}

exampleHeading('what to do next');

printf("accepted:      %d, store the receipt IDs\n", count($result->accepted()));
printf("not accepted:  %d, no device got them\n", count($result->notAccepted()));
printf("unknown:       %d, a resend can duplicate\n", count($result->unknown()));
printf("not attempted: %d, a resend is safe\n", count($result->notAttempted()));

foreach ($result->unregisteredTokens() as $dead) {
    // Only DeviceNotRegistered reaches this list. Delete the token.
    printf("delete token %s\n", $dead->fingerprint());
}

foreach ($result->requestFailures() as $failure) {
    printf(
        "request failure: chunk %d, positions %s, %s, retryable=%s\n",
        $failure->chunkOrdinal,
        json_encode($failure->indexRange()),
        $failure->category->value,
        $failure->retryable ? 'yes' : 'no'
    );
}

exampleHeading('an error ticket is data, not an exception');

foreach ($result->tickets()->errors() as $ticket) {
    printf(
        "%s -> %s (%s), token invalid: %s, may succeed later: %s\n",
        $ticket->token?->fingerprint() ?? '-',
        $ticket->errorCode ?? 'unknown',
        $ticket->classification()?->value ?? '-',
        $ticket->invalidatesToken() ? 'yes' : 'no',
        var_export($ticket->error?->maySucceedLater(), true)
    );
}

// A device error does not raise. Only a failed request does.
if ($result->hasRequestFailures()) {
    printf("\n%d request(s) produced no usable answer.\n", count($result->requestFailures()));
}

// A ticket with MessageRateExceeded is the only per device code that clearly
// says "try later".
$rateLimited = $result->tickets()->withError(PushError::MessageRateExceeded);
printf("rate limited devices: %d\n", count($rateLimited));

if ($result->outcome(0)?->acceptance === Acceptance::Accepted) {
    printf("the first device is on its way to Apple or Google\n");
}
