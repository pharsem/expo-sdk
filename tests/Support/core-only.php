<?php

declare(strict_types=1);

/**
 * Loads every class of the package with the production dependencies only.
 *
 * CI runs this after `composer install --no-dev`, so `psr/http-client` and
 * `psr/http-factory` are absent. Every class but `Psr18HttpClient` must load.
 *
 * Run it with:
 *   php tests/Support/core-only.php
 */
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Expo\Push\Expo;
use Expo\Push\PushMessage;

$root = dirname(__DIR__, 2) . '/src';
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
$loaded = 0;
$skipped = [];

foreach ($iterator as $file) {
    if (!$file instanceof SplFileInfo || $file->getExtension() !== 'php') {
        continue;
    }

    $relative = str_replace('\\', '/', $file->getPathname());
    $relative = substr($relative, strlen(str_replace('\\', '/', $root)) + 1, -4);
    $class = 'Expo\\Push\\' . str_replace('/', '\\', $relative);

    if ($class === 'Expo\\Push\\Http\\Psr18HttpClient') {
        $skipped[] = $class;

        continue;
    }

    if (!class_exists($class) && !interface_exists($class) && !enum_exists($class) && !trait_exists($class)) {
        fwrite(STDERR, sprintf("%s did not load.\n", $class));

        exit(1);
    }

    ++$loaded;
}

// A message and a client must work without any optional package.
$message = PushMessage::to('ExponentPushToken[aaaaaaaaaaaaaaaaaaaaaa]')->title('Hi')->data(['a' => 1]);
new Expo();

if ($message->sizeInBytes() < 1 || $message->toExpoArray() === []) {
    fwrite(STDERR, "The core package did not work without the optional packages.\n");

    exit(1);
}

printf("%d classes loaded, %d skipped (%s).\n", $loaded, count($skipped), implode(', ', $skipped));
printf("A message of %d bytes was built and the client was constructed.\n", $message->sizeInBytes());
