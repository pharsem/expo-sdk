# Changelog

This project uses [semantic versioning](https://semver.org/).

## 1.0.0 - unreleased

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
