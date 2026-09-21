<?php

declare(strict_types=1);

/**
 * Send through any PSR-18 client, such as Guzzle or Symfony HttpClient.
 *
 * Install the optional packages first:
 *   composer require psr/http-client psr/http-factory
 *   composer require guzzlehttp/guzzle   # or another PSR-18 client
 *
 * Run it with:
 *   php examples/psr18.php
 */

require __DIR__ . '/bootstrap.php';

use Expo\Push\Exception\InvalidConfigurationException;
use Expo\Push\Expo;
use Expo\Push\Http\Psr18HttpClient;
use Expo\Push\PushMessage;

if (!interface_exists(\Psr\Http\Client\ClientInterface::class)) {
    printf("Install psr/http-client and psr/http-factory to run this example.\n");

    exit(0);
}

exampleHeading('what the adapter can and cannot promise');

printf("One request at a time. A concurrency above 1 fails at construction.\n");
printf("No hard deadline. PHP cannot interrupt a synchronous client from outside,\n");
printf("so set the timeout on your own client. The retry budget still bounds how\n");
printf("much new work the SDK schedules.\n");
printf("The adapter decompresses a gzip or deflate body only when the client did not.\n");

exampleHeading('the code');

printf(<<<'PHP'
use Expo\Push\Expo;
use Expo\Push\Http\Psr18HttpClient;

$guzzle = new GuzzleHttp\Client(['timeout' => 30.0, 'connect_timeout' => 10.0]);
$factory = new GuzzleHttp\Psr7\HttpFactory();

$expo = new Expo(
    accessToken: $_ENV['EXPO_ACCESS_TOKEN'],
    httpClient: new Psr18HttpClient($guzzle, $factory, $factory),
);

PHP);

exampleHeading('the exception categories that the adapter keeps apart');

printf("RequestExceptionInterface  the client refused the request. Nothing left.\n");
printf("NetworkExceptionInterface  a network error. The server may have seen it.\n");
printf("ClientExceptionInterface   any other client failure.\n");
printf("A 4xx or a 5xx answer is none of these. It reaches the SDK as a response.\n");

exampleHeading('the SDK refuses what the transport cannot do');

if (class_exists(\Expo\Push\Tests\Support\Psr\FakePsr18Client::class)) {
    $factory = new \Expo\Push\Tests\Support\Psr\FakePsrFactory();
    $client = new Psr18HttpClient(new \Expo\Push\Tests\Support\Psr\FakePsr18Client(), $factory, $factory);

    try {
        new Expo(httpClient: $client, concurrency: 4);
    } catch (InvalidConfigurationException $exception) {
        printf("%s\n", $exception->getMessage());
    }

    try {
        new Expo(httpClient: $client, operationDeadlineMs: 5_000, enforceHardDeadline: true);
    } catch (InvalidConfigurationException $exception) {
        printf("%s\n", $exception->getMessage());
    }

    exampleHeading('a send through a PSR-18 client');

    $psr18 = (new \Expo\Push\Tests\Support\Psr\FakePsr18Client())
        ->queue('{"data":[{"status":"ok","id":"receipt-1"}]}');

    $expo = new Expo(httpClient: new Psr18HttpClient($psr18, $factory, $factory));
    $result = $expo->send(PushMessage::to(exampleToken())->title('Hi'));

    printf("accepted: %d, receipt %s\n", count($result->accepted()), $result->outcomes()[0]->receiptId() ?? '-');
} else {
    printf("Install the dev dependencies to run the live part of this example.\n");
}
