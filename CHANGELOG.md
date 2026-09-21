# Changelog

This project uses [semantic versioning](https://semver.org/).

## 2.0.0 - 2026-09-21

A new result model. `send()` and `receipts()` no longer raise for an operational
failure, so a late failure can never take the earlier answers with it.

[MIGRATION.md](MIGRATION.md) takes you from 1.0 to 2.0.

### Added

- `SendResult` with four exclusive acceptance states: `Accepted`, `NotAccepted`,
  `Unknown` and `NotAttempted`, one for each message and device pair, in input
  order.
- `NotificationOutcome` keeps the input position, the message key, the recipient
  position, the device, your `reference`, the real ticket, the duplicate risk and
  the earliest retry time.
- `ReceiptResult` with five states for each requested ID: `Returned`, `Missing`,
  `Malformed`, `LookupFailed` and `NotAttempted`, plus a deterministic `merge()`.
- `RequestFailure` names the exact input positions, the category, the HTTP
  status, the transport code, the Expo errors, a bounded attempt history and the
  earliest retry time.
- `ReceiptReference` and `RecoverableWork` for a lookup and a recovery in another
  process.
- One retry engine with three policies: `DeliveryRetryPolicy` by default,
  `ConservativeSendPolicy` for work where a duplicate costs more than a loss, and
  `NoRetryPolicy`.
- `RetrySettings` with explicit units: three attempts, 1000 ms of backoff with
  full jitter, a 30000 ms cap, a 10000 ms inline wait, a 60000 ms chunk budget,
  and 10000 ms and 30000 ms timeouts.
- Deferrals. A `Retry-After` that the SDK will not wait inline becomes a stored
  UTC retry time, never a shortened delay.
- Bounded concurrency from 1 to 6 through cURL multi, with a scheduler that keeps
  the input order and stops activating new chunks after a failure.
- An optional notification limiter: `RateLimiter`, `SlidingWindowRateLimiter`
  with 600 notifications each second, and per project buckets.
- An optional `Observer` with typed events and no sensitive field.
- A versioned storage envelope for every public result, plus `fromExpoArray()`
  and `fromStorageArray()` as separate parsers.
- `PushMessage::reference()`, `toExpoArray()`, `toStorageArray()` and
  `fromStorageArray()`.
- `Expo::lazyChunks()` for an input that does not fit in memory.
- `FrozenClock`, `RecordingSleeper`, `FixedJitter` and `SystemClock` for a test
  without a real wait.
- A local benchmark in `benchmarks/run.php` and nine runnable examples.

### Changed

- `Expo::send()` returns `SendResult`, not `TicketCollection`.
- `Expo::receipts()` returns `ReceiptResult`, not `ReceiptCollection`.
- `Expo::receipts()` asks for 1000 IDs in one request, the documented service
  maximum. It used 300.
- `HttpClient` takes an `HttpRequest` and reports its `capabilities()`.
- `HttpResponse` normalizes the header names and keeps repeated values.
- `CurlHttpClient` reuses its handles, keeps TLS verification on, never follows a
  redirect, and accepts a curated list of cURL options only.
- `Psr18HttpClient` tells a request error, a network error and another client
  failure apart, and decompresses a body only when the client did not.
- The send parser validates the ticket count, the status and the ID. A malformed
  entry never becomes a rejected device, and a wrong count is a protocol failure
  that keeps the readable IDs.
- The receipt parser correlates by ID, not by position.
- `PushError::isPermanent()` became `invalidatesToken()`, and `isRetryable()`
  became `maySucceedLater()`, which returns null when the code does not say.
- `PushMessage::data()` accepts an associative array or a `stdClass`, copies it,
  rejects a top level list, and sends an empty object as `{}`.
- Every JSON encode failure raises `InvalidMessageException` before the request.
- The default user agent reports `expo-sdk-php/2.0.0`.
- The notification limiter bounds a send only. A receipt lookup sends no
  notification, so it never asks for a permit.
- The SDK asks for `gzip, deflate` only when the transport or this build can
  decompress the answer. Otherwise it asks for `identity`.
- `notify(data: [])` keeps the empty JSON object, the same as `PushMessage::data([])`.

### Removed

- `ExpoApiException` and `RateLimitException`. An Expo error is now a
  `RequestFailure` in the result.
- `HttpClient::post()`. Use `send()`.
- `ReceiptCollection::pendingIds()`. `ReceiptResult::missingIds()` owns the
  coverage of a lookup.

### Fixed

- `send()` and `receipts()` kept nothing when a later chunk failed. The result
  now holds every earlier answer.
- `ReceiptCollection::filter()` kept the requested IDs, so `pendingIds()` turned
  answered IDs into pending ones.
- `PushTicket::jsonSerialize()` wrote the device token and `fromArray()` dropped
  it.
- The retry loop never repeated a thrown transport failure.
- The retry wait shortened a long `Retry-After` to the local cap.
- The send parser turned a malformed entry into a rejected device, and it
  accepted an answer with the wrong number of tickets.
- Repeated collection merges copied every earlier element again for each chunk.
- A transport failure with uploaded bytes claimed that nothing left the process.
  Evidence of sent bytes now moves the acceptance to unknown, never to a clean
  rejection.
- A truncated stored array read as an empty result. A missing list field now
  raises `InvalidStorageException`.
- `ReceiptResult::merge()` dropped the device token, the notification index and
  the reference of the later lookup.
- `Retry-After` accepted a date that does not exist, such as 32 January, and
  deferred the work.
- `Content-Encoding: deflate` stayed compressed for a zlib wrapped body.
- A repeated receipt ID kept only the first reference. The entry now carries
  every reference that asked for the ID, and a merge joins both lists.
- A stored receipt entry with the state `returned` and no receipt read back as a
  complete lookup that gives nothing. Both contradictions now raise
  `InvalidStorageException`.

## 1.0.0 - 2026-09-21

The first release.

### Added

- `Expo::send()` sends one message or many messages and returns one ticket for each device.
- `Expo::notify()` sends a title, a body and a data array in one call.
- `Expo::receipts()` reads the delivery result and keeps the device token on each receipt.
- `Expo::chunk()` groups messages into chunks of 100 notifications for a queue.
- `PushMessage` covers every field of the Expo push API.
- `PushToken` validates a token before the send.
- Automatic gzip compression above 1024 bytes.
- Automatic retry after a 429 or a 5xx answer, with exponential backoff.
- `CurlHttpClient` needs no other package. `Psr18HttpClient` uses any PSR-18 client.
- Typed class constants, readonly classes and `#[\Override]` on every interface method.
- `#[\NoDiscard]` on every method that returns a new object. PHP 8.5 warns when your
  code drops the result. PHP 8.3 and PHP 8.4 ignore the attribute.
