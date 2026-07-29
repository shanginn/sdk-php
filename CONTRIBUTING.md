# Contributing

This doc is intended for contributors to `sdk-php` (hopefully that's you!)

All contributors must complete the Temporal Contributor License Agreement (CLA) before changes can be merged. A link to the CLA will be posted in the PR.

## Development environment

- PHP 8.6+ built from [`true-async/php-src`](https://github.com/true-async/php-src)
- [`ext-true_async`](https://github.com/true-async/php-async)
- [`ext-temporal`](https://github.com/shanginn/php-temporal)
- [Composer](https://getcomposer.org/download/)

## Build

Run Composer with a stock PHP CLI while installing this repository's development
tooling. Composer 2 and `composer-patches` currently use stream-context callbacks
that PHP 8.6 TrueAsync rejects. This does not affect the SDK runtime; all test and
worker commands below must use the TrueAsync PHP executable with `ext-temporal`
loaded.

```bash
SYSTEM_PHP=/path/to/stock/php
"$SYSTEM_PHP" "$(command -v composer)" install --ignore-platform-reqs
"$SYSTEM_PHP" "$(command -v composer)" get:binaries
pecl install protobuf         # Improves performance of protobuf serialization
```

## Test

```bash
composer run test:unit        # Unit tests
composer run test:func        # Functional tests
composer run test:arch        # Architecture tests
composer run test:accept      # All acceptance tests
composer run test:accept-fast # All acceptance tests except the slow ones
composer run test:accept-slow # Only the slow acceptance tests
```

## Quality control

```bash
composer run cs:diff          # Show code style violations (dry run)
composer run cs:fix           # Auto-fix code style violations
composer run psalm            # Run static analysis
composer run psalm:baseline   # Update the Psalm baseline file
```
