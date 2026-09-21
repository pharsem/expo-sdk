<?php

declare(strict_types=1);

/**
 * Builds one recursive value in a child process and prints what happened.
 *
 * A guard that fails raises out of memory and kills the process. The test that
 * runs this script therefore runs it apart from the test runner, with a small
 * memory limit, and reads the exit code and the one line of output.
 *
 * Run it with:
 *   php tests/Support/recursive-data.php self-object
 */

use Expo\Push\Exception\InvalidMessageException;
use Expo\Push\PushMessage;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$case = $argv[1] ?? '';
$token = 'ExponentPushToken[aaaaaaaaaaaaaaaaaaaaaa]';

/** @var array<string, mixed>|stdClass $data */
$data = match ($case) {
    'self-object' => (static function (): stdClass {
        $object = new stdClass();
        $object->self = $object;

        return $object;
    })(),
    'self-array' => (static function (): array {
        $array = [];
        $array['self'] = &$array;

        /** @var array<string, mixed> $array */
        return $array;
    })(),
    'mixed-cycle' => (static function (): stdClass {
        $outer = new stdClass();
        $inner = new stdClass();
        $outer->branch = ['deeper' => $inner];
        $inner->back = $outer;

        return $outer;
    })(),
    'deep-array' => (static function (): array {
        $deep = ['leaf' => 1];

        for ($i = 0; $i < 20_000; ++$i) {
            $deep = ['n' => $deep];
        }

        return $deep;
    })(),
    default => throw new RuntimeException('Unknown case: ' . $case),
};

try {
    $message = PushMessage::to($token)->data($data);

    echo 'NO GUARD: the SDK accepted the value', PHP_EOL;

    exit(2);
} catch (InvalidMessageException $exception) {
    echo 'CAUGHT: ', substr($exception->getMessage(), 0, 60), PHP_EOL;

    exit(0);
}
