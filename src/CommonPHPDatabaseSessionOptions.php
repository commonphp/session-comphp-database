<?php

declare(strict_types=1);

namespace CommonPHP\Drivers\Session\CommonPHPDatabase;

use CommonPHP\Drivers\Session\CommonPHPDatabase\Exceptions\CommonPHPDatabaseSessionDriverException;

final readonly class CommonPHPDatabaseSessionOptions
{
    public const string DEFAULT_TABLE = 'sessions';

    public const string DEFAULT_ID_COLUMN = 'id';

    public const string DEFAULT_NAME_COLUMN = 'name';

    public const string DEFAULT_PAYLOAD_COLUMN = 'payload';

    public const string DEFAULT_LAST_ACTIVITY_COLUMN = 'last_activity';

    public const string DEFAULT_SESSION_NAME = 'COMMONPHPSESSID';

    public const int DEFAULT_LIFETIME_SECONDS = 1440;

    public const int DEFAULT_GC_PROBABILITY = 1;

    public const int DEFAULT_GC_DIVISOR = 100;

    public const int DEFAULT_ID_BYTES = 32;

    public string $table;

    public string $idColumn;

    public string $nameColumn;

    public string $payloadColumn;

    public string $lastActivityColumn;

    public string $sessionName;

    public ?string $connection;

    public int $lifetimeSeconds;

    public int $gcProbability;

    public int $gcDivisor;

    public int $idBytes;

    public function __construct(
        string $table = self::DEFAULT_TABLE,
        string $idColumn = self::DEFAULT_ID_COLUMN,
        string $nameColumn = self::DEFAULT_NAME_COLUMN,
        string $payloadColumn = self::DEFAULT_PAYLOAD_COLUMN,
        string $lastActivityColumn = self::DEFAULT_LAST_ACTIVITY_COLUMN,
        string $sessionName = self::DEFAULT_SESSION_NAME,
        ?string $connection = null,
        int $lifetimeSeconds = self::DEFAULT_LIFETIME_SECONDS,
        int $gcProbability = self::DEFAULT_GC_PROBABILITY,
        int $gcDivisor = self::DEFAULT_GC_DIVISOR,
        int $idBytes = self::DEFAULT_ID_BYTES,
    ) {
        $this->table = self::identifierPath($table, 'table');
        $this->idColumn = self::identifier($idColumn, 'idColumn');
        $this->nameColumn = self::identifier($nameColumn, 'nameColumn');
        $this->payloadColumn = self::identifier($payloadColumn, 'payloadColumn');
        $this->lastActivityColumn = self::identifier($lastActivityColumn, 'lastActivityColumn');
        $this->sessionName = self::nonEmpty($sessionName, 'sessionName');
        $this->connection = self::nullableNonEmpty($connection, 'connection');
        $this->lifetimeSeconds = self::positiveInt($lifetimeSeconds, 'lifetimeSeconds');
        $this->gcProbability = self::nonNegativeInt($gcProbability, 'gcProbability');
        $this->gcDivisor = self::positiveInt($gcDivisor, 'gcDivisor');
        $this->idBytes = self::positiveInt($idBytes, 'idBytes');

        if ($this->gcProbability > $this->gcDivisor) {
            throw CommonPHPDatabaseSessionDriverException::invalidOption(
                'gcProbability',
                'value cannot be greater than gcDivisor.',
            );
        }
    }

    /**
     * @param array<string, mixed> $options
     */
    public static function fromArray(array $options): self
    {
        return new self(
            table: self::stringOption($options, ['table'], self::DEFAULT_TABLE),
            idColumn: self::stringOption($options, ['idColumn', 'id_column'], self::DEFAULT_ID_COLUMN),
            nameColumn: self::stringOption($options, ['nameColumn', 'name_column'], self::DEFAULT_NAME_COLUMN),
            payloadColumn: self::stringOption(
                $options,
                ['payloadColumn', 'payload_column'],
                self::DEFAULT_PAYLOAD_COLUMN,
            ),
            lastActivityColumn: self::stringOption(
                $options,
                ['lastActivityColumn', 'last_activity_column'],
                self::DEFAULT_LAST_ACTIVITY_COLUMN,
            ),
            sessionName: self::stringOption(
                $options,
                ['sessionName', 'session_name', 'name'],
                self::DEFAULT_SESSION_NAME,
            ),
            connection: self::nullableStringOption($options, ['connection', 'connectionName', 'connection_name']),
            lifetimeSeconds: self::intOption(
                $options,
                ['lifetimeSeconds', 'lifetime_seconds', 'lifetime'],
                self::DEFAULT_LIFETIME_SECONDS,
            ),
            gcProbability: self::intOption(
                $options,
                ['gcProbability', 'gc_probability'],
                self::DEFAULT_GC_PROBABILITY,
            ),
            gcDivisor: self::intOption($options, ['gcDivisor', 'gc_divisor'], self::DEFAULT_GC_DIVISOR),
            idBytes: self::intOption($options, ['idBytes', 'id_bytes'], self::DEFAULT_ID_BYTES),
        );
    }

    public function columns(): string
    {
        return implode(', ', [
            $this->idColumn,
            $this->nameColumn,
            $this->payloadColumn,
            $this->lastActivityColumn,
        ]);
    }

    public function identityWhereSql(): string
    {
        return $this->idColumn . ' = :id and ' . $this->nameColumn . ' = :name';
    }

    public function selectSql(): string
    {
        return 'select ' . $this->columns() . ' from ' . $this->table . ' where ' . $this->identityWhereSql();
    }

    public function insertSql(): string
    {
        return 'insert into ' . $this->table . ' ('
            . $this->columns()
            . ') values (:id, :name, :payload, :lastActivity)';
    }

    public function updateSql(): string
    {
        return 'update ' . $this->table
            . ' set ' . $this->payloadColumn . ' = :payload, '
            . $this->lastActivityColumn . ' = :lastActivity where '
            . $this->identityWhereSql();
    }

    public function deleteSql(): string
    {
        return 'delete from ' . $this->table . ' where ' . $this->identityWhereSql();
    }

    public function deleteExpiredSql(): string
    {
        return 'delete from ' . $this->table . ' where ' . $this->lastActivityColumn . ' <= :expiredBefore';
    }

    private static function identifierPath(string $value, string $option): string
    {
        $value = trim($value);

        if ($value === '') {
            throw CommonPHPDatabaseSessionDriverException::invalidOption($option, 'value cannot be empty.');
        }

        foreach (explode('.', $value) as $segment) {
            self::assertIdentifier($segment, $option);
        }

        return $value;
    }

    private static function identifier(string $value, string $option): string
    {
        $value = trim($value);

        self::assertIdentifier($value, $option);

        return $value;
    }

    private static function assertIdentifier(string $value, string $option): void
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $value) !== 1) {
            throw CommonPHPDatabaseSessionDriverException::invalidOption(
                $option,
                'value must be an unquoted SQL identifier using letters, numbers, and underscores.',
            );
        }
    }

    private static function nonEmpty(string $value, string $option): string
    {
        $value = trim($value);

        if ($value === '') {
            throw CommonPHPDatabaseSessionDriverException::invalidOption($option, 'value cannot be empty.');
        }

        return $value;
    }

    private static function nullableNonEmpty(?string $value, string $option): ?string
    {
        if ($value === null) {
            return null;
        }

        return self::nonEmpty($value, $option);
    }

    private static function positiveInt(int $value, string $option): int
    {
        if ($value < 1) {
            throw CommonPHPDatabaseSessionDriverException::invalidOption($option, 'value must be greater than zero.');
        }

        return $value;
    }

    private static function nonNegativeInt(int $value, string $option): int
    {
        if ($value < 0) {
            throw CommonPHPDatabaseSessionDriverException::invalidOption($option, 'value cannot be negative.');
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $options
     * @param list<string> $keys
     */
    private static function value(array $options, array $keys): mixed
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $options)) {
                return $options[$key];
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $options
     * @param list<string> $keys
     */
    private static function stringOption(array $options, array $keys, string $default): string
    {
        $value = self::value($options, $keys);

        if ($value === null) {
            return $default;
        }

        if (!is_string($value)) {
            throw CommonPHPDatabaseSessionDriverException::invalidOption($keys[0], 'value must be a string.');
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $options
     * @param list<string> $keys
     */
    private static function nullableStringOption(array $options, array $keys): ?string
    {
        $value = self::value($options, $keys);

        if ($value === null) {
            return null;
        }

        if (!is_string($value)) {
            throw CommonPHPDatabaseSessionDriverException::invalidOption($keys[0], 'value must be a string or null.');
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $options
     * @param list<string> $keys
     */
    private static function intOption(array $options, array $keys, int $default): int
    {
        $value = self::value($options, $keys);

        if ($value === null) {
            return $default;
        }

        if (!is_int($value)) {
            throw CommonPHPDatabaseSessionDriverException::invalidOption($keys[0], 'value must be an integer.');
        }

        return $value;
    }
}
