<?php

declare(strict_types=1);

/**
 * Runs every example offline and stops on the first failure.
 *
 * CI runs this, so no example can drift away from the implemented API.
 *
 * Run it with:
 *   php examples/run-all.php
 */
$root = __DIR__;
$failures = 0;

$examples = glob($root . '/*.php') ?: [];

foreach ($examples as $path) {
    $name = basename($path);

    if ($name === 'run-all.php' || $name === 'bootstrap.php') {
        continue;
    }

    $output = [];
    $status = 0;

    exec(sprintf('%s %s 2>&1', escapeshellarg(PHP_BINARY), escapeshellarg($path)), $output, $status);

    printf("%-28s %s\n", $name, $status === 0 ? 'ok' : 'FAILED');

    if ($status !== 0) {
        ++$failures;
        printf("%s\n", implode("\n", $output));
    }
}

if ($failures > 0) {
    printf("\n%d example(s) failed.\n", $failures);

    exit(1);
}

printf("\nEvery example ran offline. No notification went out.\n");
