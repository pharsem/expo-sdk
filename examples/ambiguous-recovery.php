<?php

declare(strict_types=1);

/**
 * What to do when the SDK does not know whether Expo accepted a notification.
 *
 * Run it with:
 *   php examples/ambiguous-recovery.php
 */

require __DIR__ . '/bootstrap.php';

use Expo\Push\Expo;
use Expo\Push\Http\TransportFailureKind;
use Expo\Push\PushMessage;
use Expo\Push\Result\Acceptance;
use Expo\Push\Result\RecoverableWork;
use Expo\Push\Retry\ConservativeSendPolicy;
use Expo\Push\Retry\NoRetryPolicy;

exampleHeading('a timeout leaves the acceptance unknown');

$transport = new OfflineTransport();
$transport->queue(['data' => [['status' => 'ok', 'id' => 'receipt-1']]]);
$transport->queueFailure(TransportFailureKind::Timeout, 'the answer did not arrive');

$expo = new Expo(httpClient: $transport, retryPolicy: new NoRetryPolicy(), sendChunkSize: 1);
$result = $expo->send(PushMessage::to(exampleTokens(3))->title('Hi')->reference('batch-1'));

foreach ($result->outcomes() as $outcome) {
    printf("#%d %-13s duplicateRisk=%s\n", $outcome->index, $outcome->acceptance->value, $outcome->duplicateRisk ? 'yes' : 'no');
}

exampleHeading('the open work, with the risk visible');

$work = $result->recoverable();

printf("open: %d\n", $work->count());
printf("safe to send again: %d (the SDK never dispatched them)\n", count($work->notAttempted()));
printf("a resend may duplicate: %d\n", count($work->ambiguous()));

foreach ($work->ambiguous() as $outcome) {
    printf(
        "  #%d %s reason: %s\n",
        $outcome->index,
        $outcome->token->fingerprint(),
        $outcome->detail ?? 'the request may have reached Expo'
    );
}

exampleHeading('store the work and decide later');

$json = json_encode($work->toStorageArray(), JSON_THROW_ON_ERROR);
$decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

if (!is_array($decoded)) {
    exit(1);
}

/** @var array<string, mixed> $decoded */
$restored = RecoverableWork::fromStorageArray($decoded);

printf("stored %d byte(s) and read it back: %d open, %d ambiguous\n",
    strlen($json),
    $restored->count(),
    count($restored->ambiguous())
);

exampleHeading('three honest choices');

printf("1. Send the notAttempted() positions again. That cannot duplicate.\n");
printf("2. Leave the ambiguous ones. The device may already have the notification.\n");
printf("3. Send them again on purpose, and accept a possible duplicate.\n");
printf("\nA new correlation value does not make a resend idempotent. Expo has no\n");
printf("idempotency key. A collapse ID only replaces a notification that is still\n");
printf("on screen, and only on the same device.\n");

exampleHeading('a policy that never repeats an ambiguous attempt');

$strict = new OfflineTransport();
$strict->queueFailure(TransportFailureKind::Timeout);
$strict->queue(['data' => [['status' => 'ok', 'id' => 'receipt-2']]]);

$careful = new Expo(httpClient: $strict, retryPolicy: new ConservativeSendPolicy());
$second = $careful->send(PushMessage::to(exampleToken())->title('Hi'));

printf("requests sent: %d\n", count($strict->requests));
printf("acceptance: %s\n", $second->outcomes()[0]->acceptance->value);
printf("The conservative policy repeats only what it knows Expo ignored.\n");

if ($second->outcomes()[0]->acceptance === Acceptance::Unknown) {
    printf("Unknown stays unknown. The SDK does not guess.\n");
}
