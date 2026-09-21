# Expo Push SDK for PHP

Send Expo push notifications from PHP. The package covers every field of the Expo
push API, validates the tokens, splits large sends into chunks, and maps every
ticket and every receipt back to the device.

- No dependency other than `ext-curl` and `ext-json`.
- PHP 8.3, 8.4 and 8.5.
- Immutable message objects with named arguments or a fluent API.
- Static analysis at PHPStan level 8.

## PHP versions

The package runs on PHP 8.3, 8.4 and 8.5. It uses the newest feature that every
version understands.

| Feature | Since | Where |
| --- | --- | --- |
| Readonly classes | 8.2 | Every value object and the client. |
| Typed class constants | 8.3 | Every constant. |
| `#[\Override]` | 8.3 | Every method that implements an interface. |
| `#[\NoDiscard]` | 8.5 | Every method that returns a new object. |

PHP 8.5 warns you when your code drops the result of `send()`, of `receipts()`,
or of a `PushMessage` method. That result is the point of the call. PHP 8.3 and
PHP 8.4 ignore the attribute, so the package stays compatible with them.

## Install

```bash
composer require pharsem/expo-sdk
```

## Send one notification

```php
use Expo\Push\Expo;

$expo = new Expo();

$tickets = $expo->notify(
    'ExponentPushToken[xxxxxxxxxxxxxxxxxxxxxx]',
    'Your order is on the way',
    'It arrives before 18:00.',
    ['orderId' => 42],
);

if ($tickets->hasErrors()) {
    // Look at the errors below.
}
```

`notify()` covers the common case. Use `PushMessage` for every other field.

## Build a message

Both styles give the same message. Pick the one that fits your code.

```php
use Expo\Push\PushMessage;

// Fluent
$message = PushMessage::to($token)
    ->title('Your order is on the way')
    ->body('It arrives before 18:00.')
    ->data(['orderId' => 42])
    ->badge(1)
    ->channelId('orders')
    ->highPriority();

// Named arguments
$message = new PushMessage(
    to: $token,
    title: 'Your order is on the way',
    body: 'It arrives before 18:00.',
    data: ['orderId' => 42],
    badge: 1,
    channelId: 'orders',
    priority: 'high',
);

$tickets = $expo->send($message);
```

A message never changes. Every method returns a new message, so you can share a
template between sends.

```php
$template = PushMessage::to($token)->title('New release')->ttl(3600);

$expo->send([
    $template->body('Version 2.0 is ready.'),
    $template->recipients($otherToken)->body('Version 2.0 is ready.'),
]);
```

## Send to many devices

Give a list of tokens to one message, or give a list of messages to `send()`. The
SDK splits the work into requests of 100 notifications and sends them one after
the other.

```php
$tickets = $expo->send(PushMessage::to($tokens)->title('New release'));

echo count($tickets->ok()), ' accepted';
echo count($tickets->errors()), ' rejected';
```

The tickets come back in the order of the devices. Each ticket carries its token,
so you always know which device failed.

## Delete the dead tokens

Expo answers with `DeviceNotRegistered` when a person deletes the app. Delete that
token from your database at once.

```php
foreach ($tickets->unregisteredTokens() as $token) {
    $repository->delete((string) $token);
}
```

## Read the receipts

A ticket with the status `ok` means that Expo accepted the message. It does not
mean that the device got it. Wait about 15 minutes, then read the receipts. Expo
keeps a receipt for 24 hours.

```php
$receipts = $expo->receipts($tickets);

foreach ($receipts->errors() as $receipt) {
    $logger->warning('The delivery failed', [
        'token' => (string) $receipt->token,
        'error' => $receipt->errorCode,
        'message' => $receipt->message,
    ]);
}

foreach ($receipts->unregisteredTokens() as $token) {
    $repository->delete((string) $token);
}

// Expo leaves out a receipt that is not ready. Ask for it again later.
$later = $receipts->pendingIds();
```

Pass the tickets, and each receipt keeps the device token. Pass plain ID strings
when you store the IDs and read the receipts in another process.

```php
$receipts = $expo->receipts(['ticket-id-1', 'ticket-id-2']);
```

## Handle the errors

The SDK divides the problems into two groups.

**One device failed.** The ticket or the receipt holds the error. This is data, not
an exception.

```php
use Expo\Push\PushError;

foreach ($tickets->errors() as $ticket) {
    match ($ticket->error) {
        PushError::DeviceNotRegistered => $repository->delete((string) $ticket->token),
        PushError::MessageRateExceeded => $queue->later(60, $job),
        default => $logger->error($ticket->message ?? 'unknown error'),
    };
}
```

**The whole request failed.** The SDK raises an exception.

| Exception | Cause |
| --- | --- |
| `InvalidTokenException` | The value is not an Expo push token. |
| `MessageTooLargeException` | The message is above 4096 bytes. |
| `InvalidMessageException` | A field is out of range, or the message has no recipient. |
| `RateLimitException` | Expo rate limited the request after the last retry. |
| `ExpoApiException` | Expo rejected the request. Read `errorCode` and `errors`. |
| `TransportException` | The SDK could not reach Expo. |

Every exception implements `Expo\Push\Exception\ExpoException`.

```php
use Expo\Push\Exception\ExpoException;

try {
    $expo->send($message);
} catch (ExpoException $exception) {
    $logger->error($exception->getMessage());
}
```

## Error codes

| Code | What to do |
| --- | --- |
| `DeviceNotRegistered` | Delete the token. The device is gone. |
| `MessageTooBig` | Move large values out of the data field. |
| `MessageRateExceeded` | Send to that device less often. |
| `MismatchSenderId` | Check the FCM credentials of the project. |
| `InvalidCredentials` | Upload the push credentials again. |
| `InvalidProviderToken` | Check the Apple push key and the provisioning profile. |
| `ExpoError` | Try again later. |
| `ProviderError` | Apple or Google rejected the message. Try again later. |

`PushError::isPermanent()` tells you to delete the token. `PushError::isRetryable()`
tells you that a later send can work.

## Message fields

| Method | Named argument | Platform | Description |
| --- | --- | --- | --- |
| `PushMessage::to()` | `to` | both | One token, or a list of tokens. |
| `recipients()` | — | both | Replaces every device of the message. |
| `addRecipients()` | — | both | Adds more devices. |
| `title()` | `title` | both | The title of the notification. |
| `body()` | `body` | both | The text of the notification. |
| `data()` | `data` | both | Custom JSON that the app reads. |
| `withDatum()` | — | both | Adds one key to the custom JSON. |
| `subtitle()` | `subtitle` | iOS | A second line below the title. |
| `sound()` | `sound` | iOS | The sound to play. |
| `silent()` | — | iOS | Sends the notification without a sound. |
| `ttl()` | `ttl` | both | Seconds that Expo keeps the message for redelivery. |
| `expiration()` | `expiration` | both | A Unix timestamp or a `DateTimeInterface`. |
| `priority()` | `priority` | both | `default`, `normal` or `high`. |
| `highPriority()` | — | both | The short form of `priority('high')`. |
| `interruptionLevel()` | `interruptionLevel` | iOS | `active`, `critical`, `passive` or `time-sensitive`. |
| `badge()` | `badge` | iOS | The number on the app icon. |
| `channelId()` | `channelId` | Android | The notification channel. |
| `icon()` | `icon` | Android | The name of a drawable resource. |
| `image()` | `image` | both | The URL of an image in the notification. |
| `categoryId()` | `categoryId` | both | The category that holds the action buttons. |
| `mutableContent()` | `mutableContent` | iOS | Lets the app change the notification first. |
| `contentAvailable()` | `contentAvailable` | iOS | Starts the app in the background. |
| `collapseId()` | `collapseId` | both | Replaces an earlier message with the same value. |
| `tag()` | `tag` | Android | Replaces a notification on screen. |
| `threadId()` | `threadId` | iOS | Groups notifications on screen. |
| `targetContentId()` | `targetContentId` | iOS | The window to bring forward. |
| `relevanceScore()` | `relevanceScore` | iOS | A value from 0.0 to 1.0 for the summary. |
| `filterCriteria()` | `filterCriteria` | iOS | The Focus filter criteria. |

## Sounds

```php
use Expo\Push\Sound;

$message->sound('default');                     // the standard sound
$message->sound('bells.wav');                   // a file in the app bundle
$message->silent();                             // no sound
$message->sound(Sound::critical('alarm.wav', 0.8));
```

A critical alert plays in Do Not Disturb mode. Apple gives that entitlement on
request.

## Enhanced security

Expo can require an access token for every push request. Create the token in the
Expo dashboard, then give it to the client.

```php
$expo = new Expo(accessToken: $_ENV['EXPO_ACCESS_TOKEN']);
```

## Options

```php
$expo = new Expo(
    accessToken: null,   // the Expo access token
    httpClient: null,    // your own HTTP client. The default one uses cURL
    maxRetries: 2,       // retries after a 429 or a 5xx answer
    retryDelayMs: 1000,  // the first backoff delay. It doubles for each retry
    compress: true,      // gzip for a body above 1024 bytes
    validateSize: true,  // checks the 4096 byte limit before the send
);
```

## Large sends and queues

Expo accepts 100 notifications in one request and 600 notifications each second.
A send to 50000 devices needs 500 requests, so run it in a background worker.

Use `Expo::chunk()` to build the chunks yourself and put one job on the queue for
each chunk.

```php
foreach (Expo::chunk($message) as $chunk) {
    SendPushChunk::dispatch($chunk);
}
```

`Expo::chunk()` also splits a message that goes to more than 100 devices.

## Use your own HTTP client

The client takes any object with the `HttpClient` interface.

```php
use Expo\Push\Http\Psr18HttpClient;

$expo = new Expo(httpClient: new Psr18HttpClient(
    $guzzleClient,
    $requestFactory,
    $streamFactory,
));
```

The interface has one method.

```php
public function post(string $url, string $body, array $headers): HttpResponse;
```

## Test your own code

Write a small client that answers from a list. The package tests use the same
pattern, in `tests/Support/FakeHttpClient.php`.

```php
use Expo\Push\Http\HttpClient;
use Expo\Push\Http\HttpResponse;

final class FakeHttpClient implements HttpClient
{
    public function post(string $url, string $body, array $headers): HttpResponse
    {
        return new HttpResponse(200, '{"data":[{"status":"ok","id":"ticket-1"}]}');
    }
}

$expo = new Expo(httpClient: new FakeHttpClient());
```

## Validate a token before you store it

```php
use Expo\Push\Expo;
use Expo\Push\PushToken;

if (!Expo::isExpoPushToken($value)) {
    return 'That is not an Expo push token.';
}

$token = PushToken::tryFrom($value);   // returns null for a bad value
$token = new PushToken($value);        // raises InvalidTokenException
```

## Limits

| Limit | Value | The SDK handles it |
| --- | --- | --- |
| Notifications in one request | 100 | Yes. It splits the send. |
| Receipt IDs in one request | 300 | Yes. It splits the request. |
| Payload size | 4096 bytes | Yes. It checks before the send. |
| Notifications each second | 600 | No. Slow down your worker. |
| Receipt lifetime | 24 hours | No. Read the receipts in time. |

Use `$message->sizeInBytes()` to measure a message yourself.

## Run the checks

```bash
composer install
composer test      # PHPUnit
composer analyse   # PHPStan level 8
```

## License

MIT. See [LICENSE](LICENSE).
