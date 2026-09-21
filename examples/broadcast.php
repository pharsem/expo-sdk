<?php

declare(strict_types=1);

/**
 * Sends one notification to many devices and cleans the dead tokens.
 *
 * Run it with:
 *   php examples/broadcast.php tokens.txt
 *
 * The file holds one Expo push token on each line.
 */

require __DIR__ . '/../vendor/autoload.php';

use Expo\Push\Exception\ExpoException;
use Expo\Push\Expo;
use Expo\Push\PushMessage;
use Expo\Push\PushToken;

$path = $argv[1] ?? null;

if ($path === null || !is_file($path)) {
    fwrite(STDERR, "Give the path of a file with one token on each line.\n");

    exit(1);
}

$lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
$tokens = [];
$skipped = 0;

foreach ($lines as $line) {
    $token = PushToken::tryFrom($line);

    if ($token === null) {
        ++$skipped;

        continue;
    }

    $tokens[] = $token;
}

printf("%d valid tokens, %d skipped.\n", count($tokens), $skipped);

if ($tokens === []) {
    exit(0);
}

$expo = new Expo(accessToken: getenv('EXPO_ACCESS_TOKEN') ?: null);

$message = PushMessage::to($tokens)
    ->title('New release')
    ->body('Open the app to see what is new.')
    ->ttl(3600);

// Send one chunk at a time, so one failed chunk does not stop the others.
foreach (Expo::chunk($message) as $number => $chunk) {
    try {
        $tickets = $expo->send($chunk);
    } catch (ExpoException $exception) {
        printf("chunk %d failed: %s\n", $number + 1, $exception->getMessage());

        continue;
    }

    printf(
        "chunk %d: %d ok, %d errors\n",
        $number + 1,
        count($tickets->ok()),
        count($tickets->errors())
    );

    foreach ($tickets->unregisteredTokens() as $dead) {
        // Delete the token from your database here.
        printf("delete %s\n", $dead);
    }
}
