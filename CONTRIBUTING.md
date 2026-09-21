# Contributing

Thank you for your help.

## Set up the project

```bash
composer install
```

## Before you open a pull request

Run both checks. They must pass.

```bash
composer test      # PHPUnit
composer analyse   # PHPStan level 8
```

## Rules for the code

- The package supports PHP 8.3, 8.4 and 8.5. Do not use a feature of PHP 8.4 or later.
- The package has no dependency other than `ext-curl` and `ext-json`.
- A value object stays immutable. A method returns a new object.
- A new field of the Expo API needs a test that shows the JSON payload.
- A failure of one device is data, not an exception. Only a failed request raises one.

## Report a problem

Open an issue with the PHP version, the SDK version, and the payload that failed.
Remove the access token and the device tokens first.
