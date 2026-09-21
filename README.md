# Expo Push SDK for PHP

Send Expo push notifications from PHP, and always know what happened.

```php
$result = $expo->notify($token, 'Your order is on the way', 'It arrives before 18:00.');

count($result->accepted());      // Expo took these
count($result->notAccepted());   // Expo took none of these
count($result->unknown());       // the SDK cannot tell
count($result->notAttempted());  // the SDK never sent these
```

- No package dependency. It needs `ext-curl` and `ext-json`.
- PHP 8.3, 8.4 and 8.5.
- Structured results, not exceptions, for an operational failure.
- One retry engine, with honest duplicate risk.
- Optional bounded concurrency, an optional notification limiter, and an
  optional observer.
- Static analysis at PHPStan level 8.

Coming from 1.0? Read [MIGRATION.md](MIGRATION.md).

## Install

```bash
composer require pharsem/expo-sdk
```

## Three milestones

| Milestone | What reports it | What it means |
| --- | --- | --- |
| 1. Expo accepted the notification | a ticket with the status `ok` | Expo holds it |
| 2. Apple or Google took it | a receipt with the status `ok` | the provider holds it |
| 3. The device showed it | nothing | the API never reports this |

An accepted ticket is not a delivery. A successful receipt is not a delivery
either. Neither one says that a person saw the notification.

## The result model

`send()` returns one outcome for each message and device pair, in your input
order, whatever order the requests finished in.

| Acceptance | Meaning | What to do |
| --- | --- | --- |
| `Accepted` | a valid ticket confirms that Expo took it | store the receipt ID |
| `NotAccepted` | the SDK knows that Expo took nothing | read the reason |
| `Unknown` | the SDK cannot confirm and cannot rule out acceptance | decide, and see the duplicate risk |
| `NotAttempted` | the SDK never dispatched a request with it | a resend cannot duplicate |

`NotAccepted` carries a reason:

- `Rejected`: Expo answered with an error ticket, or refused the whole request
  with a 4xx status.
- `NotTransmitted`: every attempt failed before a byte left this process.

Acceptance and duplicate risk are two different things:

- A timeout after transmission is not a rejection. It is `Unknown`.
- An ambiguous attempt and then a success is `Accepted`, and
  `duplicateRisk` stays true.
- An ambiguous attempt and then a rejection stays `Unknown`. The last answer
  cannot say what the earlier attempt did.

```php
foreach ($result->outcomes() as $outcome) {
    printf(
        "#%d %s %s %s\n",
        $outcome->index,
        $outcome->acceptance->value,
        $outcome->receiptId() ?? '-',
        $outcome->duplicateRisk ? 'may duplicate' : ''
    );
}
```

## Send one notification

```php
use Expo\Push\Expo;

$expo = new Expo(accessToken: $_ENV['EXPO_ACCESS_TOKEN'] ?? null);

$result = $expo->notify(
    to: 'ExponentPushToken[xxxxxxxxxxxxxxxxxxxxxx]',
    title: 'Your order is on the way',
    body: 'It arrives before 18:00.',
    data: ['orderId' => 42],
    reference: 'order-42',
);
```

`notify()` covers the common case. Use `PushMessage` for every other field.

## Build a message

Both styles give the same message.

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

$result = $expo->send($message);
```

A message never changes. Every method returns a new message, so you can share a
template between sends.

## What is one notification

One notification is one message and one device.

- Two messages to the same token are two notifications, and each one gets its
  own ticket.
- The same token twice inside one message is one notification. The first
  position wins.
- A message with 100 devices counts as 100 notifications against the Expo limit
  of 100 for each request.

`reference` is your own correlation value. The SDK keeps it on every outcome and
in storage, and never sends it to Expo. Every recipient of one message shares it,
and two messages of one operation must not share one. A reference is not an
idempotency key.

## Errors: two kinds, and only one of them raises

**Your input or your settings are wrong.** The SDK raises before any request.

| Exception | Cause |
| --- | --- |
| `InvalidTokenException` | the value is not an Expo push token |
| `InvalidMessageException` | a field is out of range, the data is not JSON, or a reference repeats |
| `MessageTooLargeException` | the payload estimate is above 4096 bytes |
| `InvalidConfigurationException` | a setting cannot work, such as a concurrency above the transport limit |
| `InvalidStorageException` | a stored array does not match what the SDK writes |

**A request failed, or a device failed.** The SDK returns a result.

```php
$result = $expo->send($messages);

foreach ($result->requestFailures() as $failure) {
    // The exact input positions, the category, the attempts and the retry time.
    $queue->later($failure->earliestRetryAtUtcMs, $failure->indexes);
}

// Raises only when a request produced no usable answer. A device error never raises.
$result->throwIfRequestFailed();
```

`SendFailedException` carries the whole result, so nothing is lost.

## Delete the dead tokens

```php
foreach ($result->unregisteredTokens() as $token) {
    $repository->delete((string) $token);
}
```

The list holds only tokens with explicit `DeviceNotRegistered` evidence. A
payload error, a credential error and a provider problem never reach it.

Each ticket and each receipt classifies its own error:

```php
$ticket->classification();      // TokenInvalid, PayloadTooBig, Throttled, Credentials, ProviderProblem, Unknown
$ticket->invalidatesToken();    // true only for DeviceNotRegistered
$ticket->error?->maySucceedLater(); // true, false, or null when the code does not say
```

## Read the receipts

Wait some minutes after the send, then read the receipts. Expo keeps a receipt
for a limited time, so a missing receipt is not always a pending receipt.

```php
$receipts = $expo->receipts($result);          // or a TicketCollection, references, or raw IDs

$receipts->returnedIds();      // Expo answered with a receipt
$receipts->missingIds();       // not ready, not valid, or no longer kept
$receipts->malformedIds();     // the entry was not readable
$receipts->failedIds();        // the request produced no usable answer
$receipts->notAttemptedIds();  // the SDK never asked

foreach ($receipts->errors() as $receipt) {
    $logger->warning('the provider refused the notification', [
        'error' => $receipt->errorCode,
    ]);
}
```

The coverage lives on the result, not on the receipts. Filtering the receipts
can never invent a missing ID.

Read the missing IDs again later and join the two answers:

```php
$later = $expo->receipts($receipts->missingIds());
$all = $receipts->merge($later);
```

A returned receipt never falls back to missing. Two returned receipts that do
not agree keep the first one, and the ID goes to `conflicts()`.

## Store the receipt references

The reference keeps the receipt ID, the device token, the input position and
your own correlation value, so another process still knows what it reads.

```php
foreach ($result->receiptReferences() as $reference) {
    $database->insert($reference->toStorageArray());
}

// Later, in another process:
$references = array_map(ReceiptReference::fromStorageArray(...), $database->all());
$receipts = $expo->receipts($references);
```

Only an accepted ticket with an ID produces a reference. A raw ID string carries
no device unless you supply one.

The SDK asks Expo for each ID one time. When two references point at the same
ID, the entry keeps both: the first one on the entry, the rest in
`ReceiptEntry::otherReferences`. `ReceiptEntry::references()` gives you all of
them.

## Recover what is open

```php
$work = $result->recoverable();

$work->notAttempted();  // a resend cannot duplicate
$work->ambiguous();     // a resend may show the notification twice
$queue->store($work->toStorageArray());
```

The SDK never resends for you, and it never calls an ambiguous notification
safe.

## Retries

One engine runs every retry, for the sequential path and for the concurrent
path. A transport never repeats a request on its own.

### The defaults of this SDK

These numbers are choices of this SDK. Expo does not require them.

| Setting | Default | Meaning |
| --- | --- | --- |
| `maxAttempts` | 3 | the first attempt included. 1 means no retry |
| `initialBackoffMs` | 1000 | the wait before the second attempt |
| `backoffMultiplier` | 2.0 | how the wait grows |
| `maxBackoffMs` | 30000 | the largest local backoff, before the jitter |
| `maxInlineWaitMs` | 10000 | a longer wait becomes a deferral |
| `chunkBudgetMs` | 60000 | the whole life of one chunk, limiter waits included |
| `connectTimeoutMs` | 10000 | the connection of one attempt |
| `requestTimeoutMs` | 30000 | the whole attempt |
| `operationDeadlineMs` | off | bounds the whole operation |

The backoff uses full jitter: the SDK picks a random delay between zero and the
computed backoff.

### The policies

```php
use Expo\Push\Retry\ConservativeSendPolicy;
use Expo\Push\Retry\DeliveryRetryPolicy;
use Expo\Push\Retry\NoRetryPolicy;
use Expo\Push\Retry\RetrySettings;

new Expo(retryPolicy: new DeliveryRetryPolicy(new RetrySettings(maxAttempts: 5)));
new Expo(retryPolicy: new ConservativeSendPolicy());
new Expo(retryPolicy: new NoRetryPolicy());
```

`DeliveryRetryPolicy` is the default. It aims at delivery, not at exactly once.

It retries:

- HTTP 408, 429 and every 5xx status.
- A connection failure, a name resolution failure, a TLS handshake failure, a
  timeout and an interrupted transfer.

It never retries:

- Invalid input and invalid configuration.
- A certificate that does not verify.
- An ordinary 4xx status other than 408 and 429.
- A 2xx answer with a body that the SDK cannot trust. That is a protocol
  failure: the acceptance is unknown, and a repeat can double the send.
- An individual error ticket. Replaying the whole request would send the
  accepted notifications a second time.

A timeout and an interrupted transfer are ambiguous. A retry after one of them
can produce a duplicate on the device, and the result marks those notifications
with `duplicateRisk`.

`ConservativeSendPolicy` repeats a send only when the SDK knows that Expo
applied nothing: a failure before transmission, or an explicit HTTP 429. Unknown
stays unknown.

### Retry-After and deferrals

The SDK reads `Retry-After` as a number of seconds or as an HTTP date. A valid
server delay is a lower bound: a server that asks for 120 seconds gets 120
seconds, whatever the local cap says.

When the next attempt is further away than `maxInlineWaitMs`, or further than
the chunk budget allows, the SDK stops and reports a deferral:

```php
$failure = $result->requestFailures()[0];

$failure->deferred;               // true
$failure->earliestRetryAtUtcMs;   // a UTC timestamp that you can store
$failure->indexes;                // the exact input positions
```

A rate limit cooldown holds back every chunk of the same bucket in that
operation, not only the chunk that the server refused.

## Concurrency

```php
$expo = new Expo(concurrency: 4);
```

One to six, and the default is one. Six is the maximum of this SDK, not a
documented limit of the Expo service. The public API stays synchronous.

The SDK activates a chunk only when a slot is free. After a failure or a
deferral it activates no new chunk, and the chunks that are already on the wire
finish their own work. Pass `continueAfterFailure: true` to activate the later
chunks as well.

The SDK never drops a request that is already on the wire to make a result look
clean. A cancelled request after transmission is ambiguous, and ambiguity is
what the SDK reports.

The built in cURL transport supports concurrency. A PSR-18 client does not, and
the constructor says so before any request goes out.

## Rate limiting

Expo documents 600 notifications each second for one project. The SDK can hold
that limit inside one process:

```php
use Expo\Push\RateLimit\SlidingWindowRateLimiter;

$expo = new Expo(
    rateLimiter: new SlidingWindowRateLimiter(),  // 600 notifications each second
    rateLimitBucket: 'my-expo-project',           // required, and never read from a token
);
```

The window slides, so no burst passes at a window boundary. The limiter counts
notifications, not requests, and every retry takes its own permits.

The limiter bounds a send and nothing else. A receipt lookup sends no
notification, so `receipts()` never asks for a permit.

One instance coordinates every client that shares it in one PHP process. A
second process, a second pod and a second worker each get their own window.
Write your own `RateLimiter` against a shared store when you need one limit for
all of them. Two rules make that work:

1. `acquire()` must be atomic. A read, then a write, is not enough.
2. `acquire()` must never sleep. Return `PermitDecision::wait()`, and the SDK
   schedules the wait.

`examples/shared-limiter.php` shows the contract, with the Redis script in a
comment. A limiter that cannot answer fails closed: the SDK stops and keeps
every result so far.

## Observability

```php
use Expo\Push\Observability\Event;
use Expo\Push\Observability\Observer;

final class LogObserver implements Observer
{
    public function onEvent(Event $event): void
    {
        $this->logger->info($event->name(), $event->fields());
    }
}

$expo = new Expo(observer: new LogObserver($logger));
```

Every field of every event is safe to log. No device token, no message body, no
custom data, no authorization value and no raw response body reaches an event.

An observer cannot stop a retry, cannot change a result and cannot drop a
completed chunk. The SDK isolates every call, counts the failures, reports the
count one time at the end, and stops calling an observer that fails again and
again.

A storage array is the opposite: it holds real tokens and real message fields on
purpose. Never send one to a logger.

See `examples/observability.php`.

## Large sends and queues

Expo accepts 100 notifications in one request. A send to 50000 devices needs 500
requests, so run it in a background worker.

`Expo::send()` builds the whole plan before the first request, so an invalid
message in the last chunk raises before the first chunk goes out. That costs
memory for a very large input.

Use `Expo::chunk()` to build the chunks yourself, and put one job on the queue
for each chunk:

```php
foreach (Expo::chunk($message) as $chunk) {
    SendPushChunk::dispatch(array_map(
        static fn (PushMessage $message): array => $message->toStorageArray(),
        $chunk
    ));
}
```

Use `Expo::lazyChunks()` for an input that does not fit in memory. Neither
helper checks the whole operation first: a broken message in a later chunk shows
up only when that chunk runs.

Keep your queue from replaying a chunk that already produced tickets. Store the
result, and schedule only `notAttempted()` and, deliberately, `unknown()`.

## Storage

Every public result has an array form with a small versioned envelope:

```php
$stored = $result->toStorageArray();     // ['_v' => 1, '_type' => 'expo.send_result', 'data' => [...]]
$result = SendResult::fromStorageArray($stored);
```

| Class | Type name |
| --- | --- |
| `PushMessage` | `expo.message` |
| `PushTicket` | `expo.ticket` |
| `PushReceipt` | `expo.receipt` |
| `TicketCollection` | `expo.tickets` |
| `ReceiptCollection` | `expo.receipts` |
| `ReceiptReference` | `expo.receipt_reference` |
| `NotificationOutcome` | `expo.notification_outcome` |
| `RequestFailure` | `expo.request_failure` |
| `ReceiptEntry` | `expo.receipt_entry` |
| `SendResult` | `expo.send_result` |
| `ReceiptResult` | `expo.receipt_result` |
| `RecoverableWork` | `expo.recoverable_work` |

The SDK reads schema version 1 and rejects anything else with a clear message.
There is no migration framework: read an older version with your own code, or
send the work again.

A stored array never holds a live exception, an HTTP client, a clock, a callback
or a credential.

Wire parsing and storage parsing are separate. `PushTicket::fromExpoArray()`
reads an answer of the API. `PushTicket::fromStorageArray()` reads what the SDK
wrote, and gives the device token back.

## Message fields

| Method | Named argument | Platform | Description |
| --- | --- | --- | --- |
| `PushMessage::to()` | `to` | both | one token, or a list of tokens |
| `recipients()` | — | both | replaces every device of the message |
| `addRecipients()` | — | both | adds more devices, first position wins |
| `title()` | `title` | both | the title of the notification |
| `body()` | `body` | both | the text of the notification |
| `data()` | `data` | both | custom JSON that the app reads |
| `withDatum()` | — | both | adds one key to the custom JSON |
| `subtitle()` | `subtitle` | iOS | a second line below the title |
| `sound()` | `sound` | iOS | the sound to play |
| `silent()` | — | iOS | sends the notification without a sound |
| `ttl()` | `ttl` | both | seconds that Expo keeps the message for redelivery |
| `expiration()` | `expiration` | both | a Unix timestamp or a `DateTimeInterface` |
| `priority()` | `priority` | both | `default`, `normal` or `high` |
| `highPriority()` | — | both | the short form of `priority('high')` |
| `interruptionLevel()` | `interruptionLevel` | iOS | `active`, `critical`, `passive` or `time-sensitive` |
| `badge()` | `badge` | iOS | the number on the app icon |
| `channelId()` | `channelId` | Android | the notification channel |
| `icon()` | `icon` | Android | the name of a drawable resource |
| `image()` | `image` | both | the URL of an image, sent as `richContent` |
| `categoryId()` | `categoryId` | both | the category that holds the action buttons |
| `mutableContent()` | `mutableContent` | iOS | lets the app change the notification first |
| `contentAvailable()` | `contentAvailable` | iOS | starts the app in the background |
| `collapseId()` | `collapseId` | both | replaces an earlier message with the same value |
| `tag()` | `tag` | Android | replaces a notification on screen |
| `threadId()` | `threadId` | iOS | groups notifications on screen |
| `targetContentId()` | `targetContentId` | iOS | the window to bring forward |
| `relevanceScore()` | `relevanceScore` | iOS | a value from 0.0 to 1.0 for the summary |
| `filterCriteria()` | `filterCriteria` | iOS | the Focus filter criteria |
| `reference()` | `reference` | — | your correlation value. Expo never sees it |

### Rules that the message keeps

- `false` and `0` reach the wire. A missing field does not.
- `silent()` writes the literal `"sound": null`. A message without a sound
  writes no `sound` key.
- `data` must be a JSON object: an associative array, an empty array, or a
  `stdClass`. An empty array goes out as `{}`. A list such as `[1, 2, 3]` has no
  keys, so the SDK rejects it.
- The SDK copies your data. A later change to your array or object cannot reach
  the message.
- Invalid UTF-8, `NAN`, `INF`, a resource and any object other than `stdClass`
  raise `InvalidMessageException` before any request.

### The size check

```php
$message->sizeInBytes();          // an estimate in bytes
$message->assertWithinSizeLimit(); // raises MessageTooLargeException above 4096
```

The number counts the JSON that the SDK sends to Expo, without the `to` field.
It is not the size of the final Apple or Google payload, and gzip on the request
does not make it smaller. Expo can still answer `MessageTooBig`.

Pass `validateSize: false` to skip the check.

## Sounds

```php
use Expo\Push\Sound;

$message->sound('default');
$message->sound('bells.wav');
$message->silent();
$message->sound(Sound::critical('alarm.wav', 0.8));
```

A critical alert plays in Do Not Disturb mode. Apple gives that entitlement on
request.

## Tokens

```php
use Expo\Push\Expo;
use Expo\Push\PushToken;

Expo::isExpoPushToken($value);   // true for a valid shape
PushToken::tryFrom($value);      // null for a bad value
new PushToken($value);           // raises InvalidTokenException
```

The SDK accepts `ExponentPushToken[...]`, `ExpoPushToken[...]` and the bare UUID
form. It trims the surrounding whitespace and changes nothing else: the value
inside the brackets is opaque, so the SDK never changes its case.

A valid shape says nothing about registration. Only a `DeviceNotRegistered`
ticket or receipt tells you that a device is gone.

## Transports

```php
use Expo\Push\Http\CurlHttpClient;
use Expo\Push\Http\Psr18HttpClient;

new Expo(httpClient: new CurlHttpClient());
new Expo(httpClient: new Psr18HttpClient($guzzle, $factory, $factory));
```

The cURL transport is the default:

- It keeps its handles, so it reuses the TLS connection, and it resets every
  handle before each use.
- TLS verification is always on, and it never follows a redirect, so a
  credential can never travel to another host.
- It runs up to six requests at one time.
- It accepts a curated list of extra cURL options. Anything that decides the
  URL, the body, the headers, the callbacks or the TLS checks is refused.
- It talks to `http://` only when you build it with `allowPlaintextHttp: true`,
  for a local test server.

The PSR-18 adapter needs `psr/http-client` and `psr/http-factory`. It runs one
request at a time, and it cannot enforce a hard deadline: PHP cannot interrupt a
synchronous client from outside. Set the timeout on your own client. The retry
budget still bounds how much new work the SDK schedules, and
`enforceHardDeadline: true` fails at construction with this transport.

Write your own transport with the `HttpClient` interface. Three rules:

1. Return every status code. Never raise for a status.
2. Raise `TransportException` only when there is no response at all.
3. Never repeat a request. The retry engine of the SDK owns every attempt.

## Options

```php
$expo = new Expo(
    accessToken: null,            // the Expo access token
    httpClient: null,             // your own transport. The default one uses cURL
    retryPolicy: null,            // DeliveryRetryPolicy by default
    concurrency: 1,               // 1 to 6
    rateLimiter: null,            // off by default
    rateLimitBucket: null,        // required with a limiter
    observer: null,               // off by default
    compress: true,               // gzip above 1024 bytes, when zlib is there
    validateSize: true,           // the 4096 byte estimate before the send
    continueAfterFailure: false,  // activate later chunks after a failure
    operationDeadlineMs: null,    // off by default
    enforceHardDeadline: false,   // demands a transport that can stop a request
    sendChunkSize: 100,           // at most 100
    receiptChunkSize: 1000,       // at most 1000
    baseUrl: 'https://exp.host',  // change it only for a test server
    clock: null,                  // inject FrozenClock in a test
    sleeper: null,                // inject RecordingSleeper in a test
    jitter: null,                 // inject FixedJitter in a test
);
```

## Limits

| Limit | Value | The SDK handles it |
| --- | --- | --- |
| Notifications in one request | 100 | yes, it splits the send |
| Receipt IDs in one request | 1000 | yes, it splits the lookup |
| Payload size | 4096 bytes | it checks an estimate before the send |
| Notifications each second | 600 for one project | opt in, with a limiter |
| Receipt lifetime | limited, see the Expo docs | no. Read the receipts in time |
| Requests at one time | 6 in this SDK | opt in, with `concurrency` |

The first three numbers come from the Expo API documentation, checked on
2026-09-21. The concurrency maximum is a choice of this SDK.

## Test your own code

```php
use Expo\Push\Expo;
use Expo\Push\Http\HttpClient;
use Expo\Push\Http\HttpRequest;
use Expo\Push\Http\HttpResponse;
use Expo\Push\Http\TransportCapabilities;
use Expo\Push\Support\FrozenClock;
use Expo\Push\Support\RecordingSleeper;

final class FakeTransport implements HttpClient
{
    public function capabilities(): TransportCapabilities
    {
        return new TransportCapabilities();
    }

    public function send(HttpRequest $request): HttpResponse
    {
        return new HttpResponse(200, '{"data":[{"status":"ok","id":"r1"}]}');
    }
}

$clock = new FrozenClock();

$expo = new Expo(
    httpClient: new FakeTransport(),
    clock: $clock,
    sleeper: new RecordingSleeper($clock),   // records the waits instead of blocking
);
```

`tests/Support/` holds the fakes that this package uses.

## Examples

| File | Shows |
| --- | --- |
| `examples/minimal.php` | one notification and its outcome |
| `examples/mixed-batch.php` | every acceptance state in one batch |
| `examples/queue-chunks.php` | one queue job for each chunk |
| `examples/ambiguous-recovery.php` | what to do with an unknown acceptance |
| `examples/receipt-references.php` | store the references, read the receipts later |
| `examples/concurrency-and-limits.php` | opt in concurrency and limiting |
| `examples/shared-limiter.php` | the contract of a shared limiter |
| `examples/psr18.php` | a PSR-18 client |
| `examples/observability.php` | safe logging and metrics |

Every example runs offline. `php examples/run-all.php` runs all of them.

## Run the checks

```bash
composer install
composer test      # PHPUnit. No test sends a notification
composer analyse   # PHPStan level 8
composer bench     # the local benchmark
```

## Versions

The package follows [semantic versioning](https://semver.org/).

```json
{
    "require": {
        "pharsem/expo-sdk": "^2.0"
    }
}
```

[CONTRIBUTING.md](CONTRIBUTING.md) names the parts of the code that the contract
covers. [CHANGELOG.md](CHANGELOG.md) lists every release.
[MIGRATION.md](MIGRATION.md) takes you from 1.0 to 2.0.

## License

MIT. See [LICENSE](LICENSE).
