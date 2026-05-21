<?php

declare(strict_types=1);

namespace CommonPHP\Drivers\Session\CommonPHPDatabase;

use CommonPHP\Database\Contracts\DatabaseDriverInterface;
use CommonPHP\Database\Contracts\DatabaseInterface;
use CommonPHP\Drivers\Session\CommonPHPDatabase\Exceptions\CommonPHPDatabaseSessionDriverException;
use CommonPHP\Drivers\Session\CommonPHPDatabase\Exceptions\CommonPHPDatabaseSessionStorageException;
use CommonPHP\Session\Contracts\AbstractSessionDriver;
use CommonPHP\Session\Enums\SessionStatus;
use Throwable;

final class CommonPHPDatabaseSessionDriver extends AbstractSessionDriver
{
    /**
     * @var array<string, mixed>
     */
    private array $payload = [];

    private bool $active = false;

    private string $sessionId = '';

    private string $sessionName;

    private CommonPHPDatabaseSessionOptions $options;

    private SessionPayloadSerializer $serializer;

    private SessionGarbageCollector $garbageCollector;

    /**
     * @param array<string, mixed>|CommonPHPDatabaseSessionOptions|null $options
     */
    public function __construct(
        private readonly DatabaseInterface|DatabaseDriverInterface $database,
        array|CommonPHPDatabaseSessionOptions|null $options = null,
        ?SessionPayloadSerializer $serializer = null,
        ?SessionGarbageCollector $garbageCollector = null,
        ?string $sessionId = null,
        ?string $sessionName = null,
    ) {
        $this->options = is_array($options)
            ? CommonPHPDatabaseSessionOptions::fromArray($options)
            : ($options ?? new CommonPHPDatabaseSessionOptions());

        $this->serializer = $serializer ?? new SessionPayloadSerializer();
        $this->garbageCollector = $garbageCollector ?? new SessionGarbageCollector($database, $this->options);

        if ($sessionId === '') {
            throw CommonPHPDatabaseSessionStorageException::forSessionOperation(
                'configure session id: value cannot be empty',
            );
        }

        $this->sessionId = $sessionId ?? '';
        $this->sessionName = $this->normalizeSessionName($sessionName ?? $this->options->sessionName);
    }

    public function getName(): string
    {
        return 'commonphp-database-session';
    }

    public function database(): DatabaseInterface|DatabaseDriverInterface
    {
        return $this->database;
    }

    public function options(): CommonPHPDatabaseSessionOptions
    {
        return $this->options;
    }

    public function serializer(): SessionPayloadSerializer
    {
        return $this->serializer;
    }

    public function garbageCollector(): SessionGarbageCollector
    {
        return $this->garbageCollector;
    }

    public function start(): void
    {
        $this->assertSessionSupport();

        if ($this->active) {
            return;
        }

        if ($this->sessionId === '') {
            $this->sessionId = $this->generateId();
        }

        $this->maybeCollectGarbage();

        $record = $this->readRecord($this->sessionId);

        if ($record !== null && $record->isExpired(time(), $this->options->lifetimeSeconds)) {
            $this->deleteRecord($this->sessionId);
            $record = null;
        }

        $this->payload = $record === null ? [] : $record->data($this->serializer);
        $this->active = true;
    }

    public function save(): void
    {
        $this->assertCanAccessData('save session data');

        $this->writeRecord(
            SessionRecord::fromPayload(
                $this->sessionId,
                $this->sessionName,
                $this->payload,
                time(),
                $this->serializer,
            ),
        );

        $this->active = false;
    }

    public function invalidate(): void
    {
        $this->assertCanAccessData('invalidate the session');

        $this->deleteRecord($this->sessionId);
        $this->payload = [];
        $this->active = false;
    }

    public function regenerateId(bool $deleteOldSession = true): string
    {
        $this->assertCanAccessData('regenerate the session id');

        $oldId = $this->sessionId;
        $newId = $this->generateId();

        if ($deleteOldSession) {
            $this->deleteRecord($oldId);
        }

        $this->sessionId = $newId;

        return $this->sessionId;
    }

    public function status(): SessionStatus
    {
        return $this->active ? SessionStatus::Active : SessionStatus::None;
    }

    public function id(): string
    {
        return $this->sessionId;
    }

    public function setId(string $id): void
    {
        $this->assertCanConfigure('set the session id');
        $this->sessionId = $this->normalizeSessionId($id);
    }

    public function name(): string
    {
        return $this->sessionName;
    }

    public function setName(string $name): void
    {
        $this->assertCanConfigure('set the session name');
        $this->sessionName = $this->normalizeSessionName($name);
    }

    /**
     * @return array<string, mixed>
     */
    public function &data(): array
    {
        $this->assertCanAccessData('access session data');

        return $this->payload;
    }

    private function readRecord(string $sessionId): ?SessionRecord
    {
        try {
            $row = $this->database instanceof DatabaseInterface
                ? $this->database->fetchOne(
                    $this->options->selectSql(),
                    $this->identityParameters($sessionId),
                    $this->options->connection,
                )
                : $this->database->fetchOne($this->options->selectSql(), $this->identityParameters($sessionId));
        } catch (CommonPHPDatabaseSessionStorageException $exception) {
            throw $exception;
        } catch (Throwable $throwable) {
            throw CommonPHPDatabaseSessionStorageException::forSessionOperation('read', $sessionId, $throwable);
        }

        if ($row === false) {
            return null;
        }

        return SessionRecord::fromRow($row, $this->options);
    }

    private function writeRecord(SessionRecord $record): void
    {
        $exists = $this->readRecord($record->id) !== null;
        $sql = $exists ? $this->options->updateSql() : $this->options->insertSql();
        $operation = $exists ? 'write update' : 'write insert';

        $this->execute($sql, $record->parameters(), $operation, $record->id);
    }

    private function deleteRecord(string $sessionId): void
    {
        $this->execute($this->options->deleteSql(), $this->identityParameters($sessionId), 'delete', $sessionId);
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function execute(string $sql, array $parameters, string $operation, string $sessionId): void
    {
        try {
            $result = $this->database instanceof DatabaseInterface
                ? $this->database->execute($sql, $parameters, $this->options->connection)
                : $this->database->execute($sql, $parameters);
        } catch (Throwable $throwable) {
            throw CommonPHPDatabaseSessionStorageException::forSessionOperation($operation, $sessionId, $throwable);
        }

        if ($result === false) {
            throw CommonPHPDatabaseSessionStorageException::forSessionOperation($operation, $sessionId);
        }
    }

    /**
     * @return array{id: string, name: string}
     */
    private function identityParameters(string $sessionId): array
    {
        return [
            'id' => $sessionId,
            'name' => $this->sessionName,
        ];
    }

    private function maybeCollectGarbage(): void
    {
        if ($this->options->gcProbability === 0) {
            return;
        }

        try {
            $shouldCollect = random_int(1, $this->options->gcDivisor) <= $this->options->gcProbability;
        } catch (Throwable $throwable) {
            throw CommonPHPDatabaseSessionDriverException::forRandomBytes(
                'checking database session garbage collection probability',
                $throwable,
            );
        }

        if ($shouldCollect) {
            $this->garbageCollector->collect();
        }
    }

    private function generateId(): string
    {
        try {
            return bin2hex(random_bytes($this->options->idBytes));
        } catch (Throwable $throwable) {
            throw CommonPHPDatabaseSessionDriverException::forRandomId($throwable);
        }
    }

    private function assertCanConfigure(string $operation): void
    {
        if ($this->active) {
            throw CommonPHPDatabaseSessionStorageException::forSessionOperation($operation, $this->sessionId);
        }
    }

    private function normalizeSessionId(string $id): string
    {
        $id = trim($id);

        if ($id === '') {
            throw CommonPHPDatabaseSessionStorageException::forSessionOperation(
                'set the session id: value cannot be empty',
            );
        }

        return $id;
    }

    private function normalizeSessionName(string $name): string
    {
        $name = trim($name);

        if ($name === '') {
            throw CommonPHPDatabaseSessionStorageException::forSessionOperation(
                'set the session name: value cannot be empty',
            );
        }

        return $name;
    }
}
