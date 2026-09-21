<?php

declare(strict_types=1);

/**
 * Sends one notification and reads the receipt.
 *
 * Run it with:
 *   php examples/send.php "ExponentPushToken[xxxxxxxxxxxxxxxxxxxxxx]"
 */

require __DIR__ . '/../vendor/autoload.php';

use Expo\Push\Exception\ExpoException;
use Expo\Push\Expo;
use Expo\Push\PushMessage;

$token = $argv[1] ?? null;

if ($token === null) {
    fwrite(STDERR, "Give an Expo push token as the first argument.\n");

    exit(1);
}

// Set EXPO_ACCESS_TOKEN when the project uses enhanced security.
$expo = new Expo(accessToken: getenv('EXPO_ACCESS_TOKEN') ?: null);

$message = PushMessage::to($token)
    ->title('Your order is on the way')
    ->body('It arrives before 18:00.')
    ->data(['orderId' => 42, 'screen' => 'orders'])
    ->badge(1)
    ->channelId('orders')
    ->highPriority();

try {
    $tickets = $expo->send($message);
} catch (ExpoException $exception) {
    fwrite(STDERR, 'The send failed: ' . $exception->getMessage() . "\n");

    exit(1);
}

foreach ($tickets as $ticket) {
    if ($ticket->isOk()) {
        printf("ok      %s -> %s\n", $ticket->token, $ticket->id);

        continue;
    }

    printf("error   %s -> %s (%s)\n", $ticket->token, $ticket->message, $ticket->errorCode);
}

foreach ($tickets->unregisteredTokens() as $dead) {
    printf("delete  %s\n", $dead);
}

if ($tickets->ids() === []) {
    exit(0);
}

echo "\nWait about 15 minutes, then read the receipts:\n";

$receipts = $expo->receipts($tickets);

foreach ($receipts as $receipt) {
    printf("%-7s %s %s\n", $receipt->status, $receipt->id, $receipt->errorCode ?? '');
}

foreach ($receipts->pendingIds() as $pending) {
    printf("pending %s\n", $pending);
}
