# Testing

## Required dev dependencies

This package uses PHPUnit 13 for its test suite. `composer.json` already lists:

- `phpunit/phpunit:^13.1`

If PHPUnit is missing from a clone, install it with:

```bash
composer require --dev phpunit/phpunit:^13.1
```

## Running tests

Install dependencies for this repository, then run PHPUnit from this repository root:

```bash
composer install
vendor/bin/phpunit
```

On Windows, use `vendor\bin\phpunit.bat`.

## Notes

The unit suite uses a small fake CommonPHP database driver, so it does not need MySQL, SQL Server, SQLite, or any other database server. The tests cover lifecycle behavior, manager connection targeting, id regeneration, invalidation, expiry, garbage collection, option validation, and corrupt payload handling.
