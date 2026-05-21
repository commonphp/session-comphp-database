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

use CommonPHP\Database\DatabaseManager;
use CommonPHP\Drivers\Session\CommonPHPDatabase\CommonPHPDatabaseSessionDriver;
use CommonPHP\Drivers\Session\CommonPHPDatabase\CommonPHPDatabaseSessionOptions;
use CommonPHP\Session\SessionManager;

$database = DatabaseManager::connection('main', $databaseDriver);

$driver = new CommonPHPDatabaseSessionDriver(
    $database,
    new CommonPHPDatabaseSessionOptions(
        table: 'sessions',
        sessionName: 'APPSESSID',
        connection: 'main',
    ),
);

$session = new SessionManager($driver);
$session->start();
$session->set('user_id', 42);
$session->save();
```

## Driver Notes

This driver is intended for applications that already use `comphp/database` and want session data stored through a CommonPHP database connection.

The driver should keep database-backed session storage separate from the core session package while using the common database abstraction.

By default, the driver expects a table with these unquoted SQL identifiers:

```sql
create table sessions (
    id varchar(128) not null,
    name varchar(128) not null,
    payload text not null,
    last_activity integer not null,
    primary key (id, name)
);
```

Use `CommonPHPDatabaseSessionOptions` to change the table, column names, session cookie name, connection name, lifetime, garbage collection odds, or generated id byte length.

## Error Handling

Connection, read, write, destroy, garbage collection, and configuration failures should throw CommonPHP session or database exceptions instead of returning ambiguous false values.

## Documentation

- [Usage](docs/usage.md)
- [Testing](TESTING.md)
- [Contributing](CONTRIBUTING.md)
- [Security](SECURITY.md)

## License

MIT. See [LICENSE.md](LICENSE.md).
