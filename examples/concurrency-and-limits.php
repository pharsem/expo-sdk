<?php

declare(strict_types=1);

/**
 * Bounded concurrency and an in process notification limiter.
 *
 * Both are off by default. Turn them on when one worker owns the whole send of
 * one project.
 *
 * Run it with:
 *   php examples/concurrency-and-limits.php
 */

require __DIR__ . '/bootstrap.php';

use Expo\Push\Exception\InvalidConfigurationException;
use Expo\Push\Expo;
use Expo\Push\Http\CurlHttpClient;
use Expo\Push\PushMessage;
use Expo\Push\RateLimit\SlidingWindowRateLimiter;

exampleHeading('the settings');

printf("concurrency: 1 to %d. Six is the maximum of this SDK, not of Expo.\n", Expo::MAX_CONCURRENCY);
printf("the limiter counts notifications, not requests.\n");
printf("the default rate is %d notifications each second, the documented Expo limit.\n\n",
    SlidingWindowRateLimiter::EXPO_NOTIFICATIONS_PER_SECOND);

$limiter = new SlidingWindowRateLimiter();

$expo = new Expo(
    accessToken: getenv('EXPO_ACCESS_TOKEN') ?: null,
    httpClient: exampleIsLive() ? new CurlHttpClient() : new OfflineTransport(),
    concurrency: exampleIsLive() ? 4 : 1,
    rateLimiter: $limiter,
    // The bucket is your Expo project. The SDK never reads a project out of a
    // push token, so you must name it.
    rateLimitBucket: getenv('EXPO_PROJECT') ?: 'my-project',
);

exampleHeading('send');

$result = $expo->send(PushMessage::to(exampleTokens(250))->title('Broadcast'));

printf("accepted: %d of %d\n", count($result->accepted()), $result->count());
printf("permits left in this window: %d\n", $limiter->available(getenv('EXPO_PROJECT') ?: 'my-project'));

exampleHeading('what one limiter instance can promise');

printf("One instance coordinates every client that shares it in this process.\n");
printf("A second process, a second pod and a second worker each get their own window.\n");
printf("Write a RateLimiter against a shared store when you need one limit for all.\n");
printf("See examples/shared-limiter.php.\n");

exampleHeading('the SDK refuses a setting that cannot work');

try {
    new Expo(
        rateLimiter: new SlidingWindowRateLimiter(permitsPerWindow: 50),
        rateLimitBucket: 'my-project',
        sendChunkSize: 100,
    );
} catch (InvalidConfigurationException $exception) {
    printf("%s\n", $exception->getMessage());
}

try {
    new Expo(concurrency: 9);
} catch (InvalidConfigurationException $exception) {
    printf("%s\n", $exception->getMessage());
}

exampleHeading('what stops when one chunk fails');

printf("After a failure or a deferral the SDK activates no new chunk. The chunks\n");
printf("that are already on the wire finish, and their answers stay in the result.\n");
printf("Pass continueAfterFailure: true to activate the later chunks as well.\n");
