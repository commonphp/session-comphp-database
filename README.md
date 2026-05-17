# CommonPHP Session CommonPHP Database Driver

Session driver for CommonPHP that reads and stores session data through a CommonPHP Database connection.

## Requirements

- PHP `^8.5`
- `comphp/session:^0.3`
- `comphp/database:^0.3`

## Installation

Once this package is available through your Composer repositories, install it with:

```bash
composer require comphp/session-comphp-database
```

## Usage

```php
<?php

// TODO: Write usage
```

## Driver Notes

This driver is intended for applications that already use `comphp/database` and want session data stored through a CommonPHP database connection.

The driver should keep database-backed session storage separate from the core session package while using the common database abstraction.

## Error Handling

Connection, read, write, destroy, garbage collection, and configuration failures should throw CommonPHP session or database exceptions instead of returning ambiguous false values.

## Documentation

- [Usage](docs/usage.md)
- [Testing](TESTING.md)
- [Contributing](CONTRIBUTING.md)
- [Security](SECURITY.md)

## License

MIT. See [LICENSE.md](LICENSE.md).
