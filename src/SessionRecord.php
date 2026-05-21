<?php

declare(strict_types=1);

namespace CommonPHP\Drivers\Session\CommonPHPDatabase;

use CommonPHP\Drivers\Session\CommonPHPDatabase\Exceptions\CommonPHPDatabaseSessionStorageException;

final readonly class SessionRecord
{
    public function __construct(
        public string $id,
        public string $name,
        public string $payload,
        public int $lastActivity,
    ) {
        if ($this->id === '') {
            throw CommonPHPDatabaseSessionStorageException::forSessionOperation('hydrate record: id cannot be empty');
        }

        if ($this->name === '') {
            throw CommonPHPDatabaseSessionStorageException::forSessionOperation('hydrate record: name cannot be empty');
        }

        if ($this->lastActivity < 0) {
            throw CommonPHPDatabaseSessionStorageException::forSessionOperation(
                'hydrate record: last activity cannot be negative',
                $this->id,
            );
        }
    }

    /**
     * @param array<string|int, mixed> $row
     */
    public static function fromRow(array $row, CommonPHPDatabaseSessionOptions $options): self
    {
        return new self(
            id: self::stringValue($row, $options->idColumn),
            name: self::stringValue($row, $options->nameColumn),
            payload: self::stringValue($row, $options->payloadColumn),
            lastActivity: self::intValue($row, $options->lastActivityColumn),
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromPayload(
        string $id,
        string $name,
        array $payload,
        int $lastActivity,
        SessionPayloadSerializer $serializer,
    ): self {
        return new self($id, $name, $serializer->encode($payload), $lastActivity);
    }

    public function isExpired(int $now, int $lifetimeSeconds): bool
    {
        return $this->lastActivity <= $now - $lifetimeSeconds;
    }

    /**
     * @return array<string, mixed>
     */
    public function data(SessionPayloadSerializer $serializer): array
    {
        return $serializer->decode($this->payload);
    }

    /**
     * @return array{id: string, name: string, payload: string, lastActivity: int}
     */
    public function parameters(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'payload' => $this->payload,
            'lastActivity' => $this->lastActivity,
        ];
    }

    /**
     * @param array<string|int, mixed> $row
     */
    private static function stringValue(array $row, string $column): string
    {
        if (!array_key_exists($column, $row)) {
            throw CommonPHPDatabaseSessionStorageException::forSessionOperation(
                'hydrate record: missing column "' . $column . '"',
            );
        }

        $value = $row[$column];

        if (is_scalar($value)) {
            return (string) $value;
        }

        throw CommonPHPDatabaseSessionStorageException::forSessionOperation(
            'hydrate record: column "' . $column . '" is not scalar',
        );
    }

    /**
     * @param array<string|int, mixed> $row
     */
    private static function intValue(array $row, string $column): int
    {
        if (!array_key_exists($column, $row)) {
            throw CommonPHPDatabaseSessionStorageException::forSessionOperation(
                'hydrate record: missing column "' . $column . '"',
            );
        }

        $value = $row[$column];

        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) {
            return (int) $value;
        }

        throw CommonPHPDatabaseSessionStorageException::forSessionOperation(
            'hydrate record: column "' . $column . '" is not an integer',
        );
    }
}
