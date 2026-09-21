# Changelog

This project uses [semantic versioning](https://semver.org/).

## 3.0.0 - 2026-09-21

A reliability patch. Recovery, scheduling, persistence and merging now tell one
consistent story about what Expo accepted, what stays uncertain, when work can
go out again, and which evidence has to survive.

### Fixed

- `recoverable()` keeps a notification that Expo refused for a transient
  reason. A 429 with a `Retry-After` header left the worklist before, because
  the selection read the acceptance alone. It now reads the acceptance and the
  recovery disposition together.
- A server `Retry-After` holds back every chunk of the bucket. Before, it held
  back only the chunk that received it, so a fresh chunk started in the freed
  slot and walked past a delay that the project already owed.
- `operationDeadlineMs` bounds every wait. Before, the scheduler could sleep a
  five second retry delay inside a 100 millisecond call. A chunk with a spent
  budget could also build another request, and its timeout lost its clamp.
- `PushMessage` rejects recursive and excessively deep data with
  `InvalidMessageException`. Before, the copy walked the value until PHP ran out
  of memory, which ended the process instead of raising.
- The storage readers check the evidence of every claim. Before, an accepted
  outcome could come back without a ticket, a broken index became zero, and a
  missing `duplicateRisk` became "no risk".
- `ReceiptResult::merge()` unites the conflicts of both sides. Before, it kept
  only the conflicts of the left operand. Receipt equality now reads the
  structured `details` as well, and ignores the order of their keys.
- A message is immutable at every level. Before, a public read of the `data`
  property handed out the stored `stdClass`, so
  `$message->data->orderId = 456` changed the payload.
- A credential error keeps the work open. Before, `InvalidCredentials`,
  `InvalidProviderToken` and `MismatchSenderId` closed it, although the token
  stays valid and a fix makes the notification sendable. Only
  `DeviceNotRegistered` and `MessageTooBig` close the work now.
- `needsAttention()` reads the same rule as `recoverable()`. Before, an open
  `MessageRateExceeded` ticket brought no request failure, so the two answers
  disagreed.
- `receiptId()` needs a ticket that reports success. Before, an outcome that
  claimed acceptance with an error ticket still gave an ID back, and
  `isCompleteSuccess()` believed it.
- A message with numeric data keys survives storage. Before, the round trip
  turned the object into a PHP list and the constructor refused it.
- A stored `recovery` value may not claim less than the evidence demands, and an
  explicit `null` is a broken field. Before, changing a retryable 429 to `none`
  dropped that work out of every recovery list in silence.
- An outcome that points at a request failure which is not there no longer
  loads. The same check covers a lookup entry and its failure.
- Every later reference of one receipt entry names that entry's ID and device.
  Before, storage could attach the correlation of another notification.
- Two answers that give one receipt ID two different devices are a conflict.
- A present request-failure field of the wrong type raises. Before,
  `httpStatus`, `transportCode` and `earliestRetryAtUtcMs` became null, so a
  corrupted retry time read as "retry now".
- `Json::snapshot()` checks an object property name for UTF-8, as it already
  did for an array key.
- `JsonObject::fromNormalized()` checks what it wraps. Before, a caller could
  wrap a live `stdClass` and reopen the mutation path.
- A reused `JsonObject` counts its own nesting inside the value that holds it.
  Before, nesting a valid 512 level object one level down passed the copy and
  failed at the encode.
- A limiter that blocks past the operation deadline reports `Deadline`, and it
  keeps the moment that the limiter asked for. Before, it reported
  `RateLimited` although the deadline ended the work.
- A stored recovery value must match the error code of a rejected device. A
  request failure decides the disposition of its own chunk, so work behind one
  only has to stay open.
- The acceptance and the evidence of a stored outcome must fit each other. Only
  an accepted notification holds a successful ticket. Every uncertain one
  carries a duplicate risk, and no rejected one does.
- `PushTicket::fromStorageArray()` and `PushReceipt::fromStorageArray()` reject
  a present `token`, `message` or `errorCode` of the wrong type. Before, a
  numeric error code became an unknown error, which turned a permanent
  rejection into work that waits for a fix.
- A stored token must look like an Expo push token. Before, a broken one
  escaped as `InvalidTokenException` from a reader that promises
  `InvalidStorageException`.
- Every part of one receipt entry names the same device. Before, an entry
  without its own token accepted a receipt for one device and a later reference
  for another.
- Every request failure holds at least one notification or one receipt ID.
- A send failure and its outcomes point at each other. Before, only one
  direction was checked. A lookup keeps the one-way check, because a merge
  leaves a failed request on the record after a later lookup answers.
- `Json::sameJson()` compares two numbers as the SDK writes them. Before, `1`
  and `1.0` read as a conflict although both go on the wire as `1`.
- `Json::sameJson()` is bounded. The public details of a receipt accept any
  array, so a value that loops exhausted the memory of the process before.
- Every chunk that the operation deadline catches reports `Deadline`. Before, a
  chunk behind one that the deadline caught could report `Skipped`.
- A limiter that spends the chunk budget reports `Deadline` as well, and it
  keeps the cooldown that the limiter asked for.
- An empty stored data object comes back as an object. Before, it came back as
  an array, and `withDatum()` with a numeric key then failed on the restored
  message.
- A present ticket `id` of the wrong type raises. Before, an error ticket lost
  that evidence in silence.
- `NotificationOutcome::fromStorageArray()` checks the token shape, as the
  other readers already did.
- A stored outcome with a rejection ticket cannot carry the reason
  `NotTransmitted`. Expo answered it, so the reason is a rejection.
- A merge of two devices for one receipt ID keeps one device, and the
  correlation of the other one does not join the entry. Before, the merged
  result could not read its own storage.
- A result holds only the failures of its own operation.
- `JsonObject::keys()` gives back strings. PHP turns a numeric property name
  into an integer array key, and the declared type promised strings.


### Added

- `NotificationOutcome::$recovery`, a `RecoveryDisposition` of `None`,
  `Retryable` or `NeedsIntervention`, plus `isOpen()`, `isRetryable()`,
  `needsIntervention()` and `isDueAt()`.
- `RecoverableWork::retryable()`, `needsIntervention()` and `dueAt()`. The
  `summary()` array gains a `retryable` and a `needsIntervention` count.
- `Expo\Push\Support\JsonObject`, the immutable form of a JSON object. It
  reads like a `stdClass` and refuses every write.
- `Json::MAX_DEPTH`, the documented nesting limit of 512 levels, and
  `Json::sameJson()` for a semantic comparison of two decoded values.
- A benchmark step that measures the bounded data copy.
- `PushMessage::MAX_DATA_DEPTH` and `JsonObject::depth()`.

### Changed

- `PushMessage::$data` holds an `array` or a `JsonObject`, no longer a
  `stdClass`. A read of a key still works. `get_object_vars()`, an `(array)`
  cast and an `instanceof stdClass` check do not: use `toArray()`.
- A chunk that the operation deadline caught reports `FailureCategory::Deadline`
  instead of `Skipped`, and it keeps its own retry time.
- A chunk that an earlier permanent failure stopped is no longer marked
  retryable. It reports `NeedsIntervention` instead.
- The storage shape of `NotificationOutcome` gains a `recovery` field. Schema
  version 1 stays. An outcome written by 2.0.0 still reads: the reader derives
  the careful disposition, which never turns open work into closed work.

### Compatibility

Three of the changes above go past a patch release, measured against the
contract in [CONTRIBUTING.md](CONTRIBUTING.md):

1. `PushMessage::$data` changes its declared type. Code that reads a key still
   works. Code that calls `get_object_vars()`, casts with `(array)`, or checks
   `instanceof stdClass` needs `toArray()` instead.
2. `RecoveryDisposition` is a new enum, and `NotificationOutcome::$recovery` is
   a new public property. An application that matches on every case of an enum
   has a new value to read.
3. The storage readers reject arrays that 2.0.0 accepted. Every array that
   2.0.0 wrote still reads. An array that something else wrote, with a missing
   or wrongly typed field, now raises `InvalidStorageException`.

Each of the three is a major change under the contract in
[CONTRIBUTING.md](CONTRIBUTING.md), so this release carries the major number.
[MIGRATION.md](MIGRATION.md) takes you from 2.0 to 3.0.

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
- A transport that refused a request before it started raised from `send()` with
  a concurrency above 1, and returned a result with a concurrency of 1. Both
  paths now return a result.
- A deferred limiter wait left the bucket cooldown in place, so the SDK slept the
  whole delay before it marked the later chunks skipped.
- A malformed ticket entry reported no duplicate risk. Expo answered and may have
  accepted the notification, so a resend can duplicate it.
- `ConservativeSendPolicy` repeated a certificate failure and a wrong transport
  setting. It now repeats only what the delivery rules also call transient.
- The backoff stopped growing after 30 steps, below the configured cap.
- A stored ticket with the status `ok` and no receipt ID read back as accepted
  with nothing to look up.

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
