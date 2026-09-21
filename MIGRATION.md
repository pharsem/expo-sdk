# Migration

[From 2.0 to 3.0](#from-20-to-30) covers this release.
[From 1.0 to 2.0](#from-10-to-20) follows it.

## From 2.0 to 3.0

Version 3.0 hardens what 2.0 built. Recovery, scheduling, persistence and
merging now tell one story about what Expo accepted and what is left to do.
Three changes need a look at your code.

### 1. Message data reads back as a `JsonObject`

`PushMessage::$data` holds an `array` or an `Expo\Push\Support\JsonObject`. A
`stdClass` that you pass in becomes a `JsonObject`, which reads the same way and
refuses every write. Nothing outside the message can change what it sends.

```php
$message = PushMessage::to($token)->data((object) ['orderId' => 123]);

$message->data->orderId;         // 123, as before
$message->data->orderId = 456;   // LogicException, new in 3.0
```

Three habits of `stdClass` need `toArray()` instead:

| 2.0 | 3.0 |
| --- | --- |
| `get_object_vars($message->data)` | `$message->data->toArray()` |
| `(array) $message->data` | `$message->data->toArray()` |
| `$message->data instanceof stdClass` | `$message->data instanceof JsonObject` |

The data that you give the SDK does not change. An array stays an array, an
object stays an object on the wire, and `toExpoArray()` writes the same JSON.

Data may nest 511 levels now, one less than the limit of `json_encode()`. The
message object itself is the level that the SDK reserves.
`PushMessage::MAX_DATA_DEPTH` holds the number.

### 2. Every outcome carries a recovery disposition

`NotificationOutcome::$recovery` says what is left to do, next to the acceptance
that says what Expo did:

| Recovery | Meaning |
| --- | --- |
| `None` | Expo took it, or refused it for good |
| `Retryable` | the failure can pass later |
| `NeedsIntervention` | fix the cause first |

`recoverable()` reads it. A notification that Expo refused with a `429` is open
work now, and 2.0 dropped it. Replace a worklist that reads `notAttempted()` and
`unknown()` alone:

```php
$work = $result->recoverable();

foreach ($work->dueAt($nowUtcMillis) as $outcome) { /* send it again */ }
foreach ($work->needsIntervention() as $outcome) { /* fix the cause */ }
```

`RecoverableWork::summary()` gains a `retryable` and a `needsIntervention`
count. `needsAttention()` reads the same rule as `recoverable()`, so the two can
no longer disagree.

### 3. The storage readers demand their evidence

`fromStorageArray()` rejects an array that 2.0 accepted. Every array that 2.0
*wrote* still reads, so a stored result of 2.0 loads without a change.

A hand-built or corrupted array raises `InvalidStorageException` when a required
field is missing, a present field holds the wrong type, or two fields
contradict each other. Read the "Storage" section of the README for the rules.

Schema version 1 stays. An outcome that 2.0 wrote holds no `recovery` field, and
the reader derives the careful value for it.

### Nothing else moves

Every other method, every other name and every storage type stay as they are.
The retry policies, the concurrency, the limiter and the observer do not change.

## From 1.0 to 2.0

Version 2.0 changes what `send()` and `receipts()` return, and it removes the
exceptions that hid the successful part of a batch. Read this page once, change
the call sites, and the rest of your code stays.

The change is intentional. In 1.0 a failure in the last chunk threw away the
tickets of every earlier chunk. In 2.0 the result always holds them.

## The short version

| 1.0 | 2.0 |
| --- | --- |
| `send()` returns `TicketCollection` | `send()` returns `SendResult` |
| `receipts()` returns `ReceiptCollection` | `receipts()` returns `ReceiptResult` |
| a failed request raises `ExpoApiException` | the result holds a `RequestFailure` |
| a rate limit raises `RateLimitException` | the result holds a deferral |
| `TransportException` leaves the call | the retry engine classifies it |
| `maxRetries`, `retryDelayMs` | a `RetryPolicy` with `RetrySettings` |
| `PushError::isPermanent()` | `PushError::invalidatesToken()` |
| `PushError::isRetryable()` | `PushError::maySucceedLater()`, which can return null |
| `ReceiptCollection::pendingIds()` | `ReceiptResult::missingIds()` |
| `HttpClient::post()` | `HttpClient::send()` with `HttpRequest` |
| receipt lookups of 300 IDs | receipt lookups of 1000 IDs |

## 1. Read the send result

**1.0**

```php
try {
    $tickets = $expo->send($messages);
} catch (ExpoApiException $exception) {
    // Every ticket of every earlier chunk is gone.
    $logger->error($exception->getMessage());

    return;
}

foreach ($tickets->ok() as $ticket) {
    $store->save($ticket->id);
}
```

**2.0**

```php
$result = $expo->send($messages);

foreach ($result->accepted() as $outcome) {
    $store->save($outcome->receiptId());
}

foreach ($result->requestFailures() as $failure) {
    $logger->error('a send request failed', [
        'chunk' => $failure->chunkOrdinal,
        'category' => $failure->category->value,
        'positions' => $failure->indexRange(),
    ]);
}
```

Keep the old shape when you want the exception:

```php
$result = $expo->send($messages)->throwIfRequestFailed();
```

`SendFailedException` carries the whole result in `$exception->result`, so
nothing is lost.

`$result->tickets()` gives you a `TicketCollection` again, with the real tickets
only. An empty collection no longer means success: read
`$result->isCompleteSuccess()`.

## 2. Read the four acceptance states

1.0 knew two states: a ticket, or an exception. 2.0 knows four.

```php
$result->accepted();      // Expo took them
$result->notAccepted();   // Expo took none of them
$result->unknown();       // the SDK cannot tell
$result->notAttempted();  // the SDK never sent them
```

A timeout used to raise `TransportException` and you had to guess. Now it lands
in `unknown()`, and `duplicateRisk` says whether a resend can show the
notification twice.

## 3. Replace the retry settings

**1.0**

```php
$expo = new Expo(maxRetries: 2, retryDelayMs: 1000);
```

**2.0**

```php
use Expo\Push\Retry\DeliveryRetryPolicy;
use Expo\Push\Retry\NoRetryPolicy;
use Expo\Push\Retry\RetrySettings;

$expo = new Expo(retryPolicy: new DeliveryRetryPolicy(new RetrySettings(
    maxAttempts: 3,          // the first attempt included: 2 retries
    initialBackoffMs: 1000,
)));

$expo = new Expo(retryPolicy: new NoRetryPolicy());   // exactly one attempt
```

`maxAttempts` counts the first attempt. `maxRetries: 2` becomes
`maxAttempts: 3`.

The engine now retries a transport failure too. 1.0 let a `TransportException`
leave the loop on the first attempt.

A long `Retry-After` no longer shrinks to a local cap. The SDK waits the full
delay, or it defers and tells you when to come back.

## 4. Read the receipt result

**1.0**

```php
$receipts = $expo->receipts($tickets);

foreach ($receipts->errors() as $receipt) { /* ... */ }

$later = $receipts->pendingIds();   // wrong after a filter
```

**2.0**

```php
$result = $expo->receipts($tickets);

foreach ($result->errors() as $receipt) { /* ... */ }

$later = $result->missingIds();     // the coverage lives on the result
```

`ReceiptCollection` no longer knows what you asked for, so filtering it cannot
invent a missing ID. `ReceiptResult::pendingIds()` still exists and returns the
same list as `missingIds()`, with a narrow meaning: the lookup worked and the
answer held no entry. That can mean not ready, not valid, or no longer kept.

`ReceiptResult` also knows `malformedIds()`, `failedIds()` and
`notAttemptedIds()`, which 1.0 could not tell apart.

## 5. Rename the error helpers

```php
$error->isPermanent();   // 1.0
$error->invalidatesToken();   // 2.0, true only for DeviceNotRegistered

$error->isRetryable();   // 1.0, true for ExpoError and ProviderError
$error->maySucceedLater();   // 2.0: true, false, or null when the code does not say
```

`isPermanent()` suggested that every lasting error kills the token. A credential
error lasts until you fix the credentials, and the token stays valid.

`isRetryable()` claimed to know that a provider error passes later. It does not.
`maySucceedLater()` returns `null` for `ExpoError` and `ProviderError`, and
`$ticket->classification()` gives you the group.

## 6. Update a custom transport

**1.0**

```php
final class MyClient implements HttpClient
{
    public function post(string $url, string $body, array $headers): HttpResponse
    {
        // ...
    }
}
```

**2.0**

```php
use Expo\Push\Http\HttpClient;
use Expo\Push\Http\HttpRequest;
use Expo\Push\Http\HttpResponse;
use Expo\Push\Http\TransportCapabilities;

final class MyClient implements HttpClient
{
    public function capabilities(): TransportCapabilities
    {
        return new TransportCapabilities();   // one request, no hard deadline
    }

    public function send(HttpRequest $request): HttpResponse
    {
        // $request holds the URL, the body, the headers and the two timeouts.
        return new HttpResponse($status, $body, $headers);
    }
}
```

`HttpResponse` now normalizes the header names and keeps repeated values.
`$response->header('retry-after')` gives the first value, and
`$response->headerValues('retry-after')` gives all of them.

`CurlHttpClient` no longer takes free cURL options. It accepts a curated list,
and it refuses anything that decides the URL, the body, the headers, the
callbacks or the TLS checks.

## 7. Update the storage format

1.0 serialized a ticket with `jsonSerialize()`, and `fromArray()` dropped the
device token. 2.0 keeps the two directions apart:

```php
PushTicket::fromExpoArray($entry, $token);   // one entry of the Expo answer
PushTicket::fromStorageArray($stored);       // what the SDK wrote, token included
$ticket->toStorageArray();                   // ['_v' => 1, '_type' => 'expo.ticket', 'data' => [...]]
```

Every stored array now carries a version and a type name, and the SDK rejects an
unknown version with a clear message. A 1.0 array has no envelope, so a 2.0
reader refuses it. Read your old rows with your own code, or send the work
again.

## 8. Removed classes

| Removed | Replacement |
| --- | --- |
| `ExpoApiException` | `RequestFailure` with `FailureCategory::Api`, or `SendFailedException` |
| `RateLimitException` | `RequestFailure` with `FailureCategory::RateLimited` and a deferral |
| `Expo::MESSAGE_CHUNK_LIMIT` usage as a receipt limit | `Expo::RECEIPT_CHUNK_LIMIT`, now 1000 |

`TransportException` stays, and it now carries a structured `TransportFailure`.
The SDK catches it inside the retry engine, so `send()` does not raise it.

## 9. New things that you may want

- `concurrency: 1..6` with the cURL transport.
- `rateLimiter` and `rateLimitBucket` for the documented 600 notifications each
  second.
- `observer` for a lifecycle log with no sensitive field.
- `reference` on a message, kept on every outcome and never sent to Expo.
- `$result->recoverable()` for the open work, with the duplicate risk visible.
- `Expo::lazyChunks()` for an input that does not fit in memory.
- `FrozenClock`, `RecordingSleeper` and `FixedJitter` for a test without a real
  wait.

## 10. What did not change

- The package name and the `Expo\Push` namespace.
- `PushMessage`, its fluent methods and its named arguments.
- `Expo::notify()` and `Expo::chunk()`.
- `PushToken`, `Sound`, `Priority`, `InterruptionLevel` and `PushError`.
- `TicketCollection` and `ReceiptCollection` as collections of real answers.
- The minimum PHP version, 8.3.
- Zero mandatory Composer dependencies.
