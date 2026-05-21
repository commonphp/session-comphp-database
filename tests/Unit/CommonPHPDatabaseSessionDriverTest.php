<?php

declare(strict_types=1);

namespace CommonPHP\Drivers\Session\CommonPHPDatabase\Tests\Unit;

use CommonPHP\Database\Contracts\AbstractDatabaseDriver;
use CommonPHP\Database\DatabaseManager;
use CommonPHP\Database\Enums\FetchMode;
use CommonPHP\Drivers\Session\CommonPHPDatabase\CommonPHPDatabaseSessionDriver;
use CommonPHP\Drivers\Session\CommonPHPDatabase\CommonPHPDatabaseSessionOptions;
use CommonPHP\Drivers\Session\CommonPHPDatabase\Exceptions\CommonPHPDatabaseSessionDriverException;
use CommonPHP\Drivers\Session\CommonPHPDatabase\Exceptions\CommonPHPDatabaseSessionStorageException;
use CommonPHP\Drivers\Session\CommonPHPDatabase\SessionPayloadSerializer;
use CommonPHP\Runtime\Contracts\DriverInterface;
use CommonPHP\Session\Contracts\SessionDriverInterface;
use CommonPHP\Session\Enums\SessionStatus;
use CommonPHP\Session\SessionManager;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class CommonPHPDatabaseSessionDriverTest extends TestCase
{
    public function testItLoadsMutatesAndSavesDatabaseSessions(): void
    {
        $serializer = new SessionPayloadSerializer();
        $database = new FakeSessionDatabaseDriver();
        $database->seed('existing', 'COMMONPHPSESSID', ['user' => 'Ada'], time(), $serializer);
        $driver = new CommonPHPDatabaseSessionDriver(
            $database,
            new CommonPHPDatabaseSessionOptions(gcProbability: 0),
            sessionId: 'existing',
        );
        $session = new SessionManager($driver);

        self::assertInstanceOf(SessionDriverInterface::class, $driver);
        self::assertInstanceOf(DriverInterface::class, $driver);
        self::assertSame(SessionStatus::None, $driver->status());

        $session->start();

        self::assertSame(SessionStatus::Active, $driver->status());
        self::assertSame('Ada', $session->get('user'));

        $session->set('theme', 'dark')->save();

        self::assertSame(SessionStatus::None, $driver->status());
        self::assertSame(
            ['user' => 'Ada', 'theme' => 'dark'],
            $database->payload('existing', 'COMMONPHPSESSID', $serializer),
        );
    }

    public function testItCreatesNewIdsAndInsertsNewSessions(): void
    {
        $serializer = new SessionPayloadSerializer();
        $database = new FakeSessionDatabaseDriver();
        $driver = new CommonPHPDatabaseSessionDriver(
            $database,
            new CommonPHPDatabaseSessionOptions(
                sessionName: 'APPSESSID',
                gcProbability: 0,
                idBytes: 16,
            ),
        );
        $session = new SessionManager($driver);

        $session->start();
        $id = $session->id();

        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $id);
        self::assertSame('APPSESSID', $session->name());

        $session->set('cart', ['sku' => 'A-1'])->save();

        self::assertTrue($database->has($id, 'APPSESSID'));
        self::assertSame(['cart' => ['sku' => 'A-1']], $database->payload($id, 'APPSESSID', $serializer));
    }

    public function testItCanUseConfiguredManagerConnections(): void
    {
        $database = new FakeSessionDatabaseDriver();
        $sessions = new FakeSessionDatabaseDriver();
        $sessions->seed('named', 'COMMONPHPSESSID', ['tenant' => 'acme'], time());
        $manager = new DatabaseManager();
        $manager->connect('default', $database, default: true);
        $manager->connect('sessions', $sessions);
        $driver = new CommonPHPDatabaseSessionDriver(
            $manager,
            new CommonPHPDatabaseSessionOptions(connection: 'sessions', gcProbability: 0),
            sessionId: 'named',
        );
        $session = new SessionManager($driver);

        $session->start();

        self::assertSame('acme', $session->get('tenant'));
        self::assertSame([], $database->log);
        self::assertNotSame([], $sessions->log);
    }

    public function testItInvalidatesAndRegeneratesIds(): void
    {
        $serializer = new SessionPayloadSerializer();
        $database = new FakeSessionDatabaseDriver();
        $database->seed('old', 'COMMONPHPSESSID', ['count' => 1], time(), $serializer);
        $driver = new CommonPHPDatabaseSessionDriver(
            $database,
            new CommonPHPDatabaseSessionOptions(gcProbability: 0, idBytes: 16),
            sessionId: 'old',
        );
        $session = new SessionManager($driver);
        $session->start();

        $newId = $session->regenerateId();

        self::assertNotSame('old', $newId);
        self::assertFalse($database->has('old', 'COMMONPHPSESSID'));

        $session->set('count', 2)->save();

        self::assertTrue($database->has($newId, 'COMMONPHPSESSID'));
        self::assertSame(['count' => 2], $database->payload($newId, 'COMMONPHPSESSID', $serializer));

        $session->start()->invalidate();

        self::assertFalse($database->has($newId, 'COMMONPHPSESSID'));
        self::assertSame(SessionStatus::None, $driver->status());
    }

    public function testExpiredSessionsStartEmptyAndGarbageCollectionRemovesOldRows(): void
    {
        $database = new FakeSessionDatabaseDriver();
        $database->seed('expired', 'COMMONPHPSESSID', ['stale' => true], time() - 10);
        $database->seed('fresh', 'COMMONPHPSESSID', ['fresh' => true], time());
        $driver = new CommonPHPDatabaseSessionDriver(
            $database,
            new CommonPHPDatabaseSessionOptions(lifetimeSeconds: 5, gcProbability: 1, gcDivisor: 1),
            sessionId: 'fresh',
        );
        $session = new SessionManager($driver);

        $session->start();

        self::assertSame(['fresh' => true], $session->all());
        self::assertFalse($database->has('expired', 'COMMONPHPSESSID'));
        self::assertTrue($database->has('fresh', 'COMMONPHPSESSID'));

        $expiredDriver = new CommonPHPDatabaseSessionDriver(
            $database,
            new CommonPHPDatabaseSessionOptions(lifetimeSeconds: 5, gcProbability: 0),
            sessionId: 'expired',
        );
        $expiredSession = new SessionManager($expiredDriver);
        $expiredSession->start();

        self::assertSame([], $expiredSession->all());
    }

    public function testItRejectsInvalidOptionsAndCorruptPayloads(): void
    {
        $this->expectException(CommonPHPDatabaseSessionDriverException::class);

        new CommonPHPDatabaseSessionOptions(table: 'sessions; drop table users');
    }

    public function testCorruptPayloadsThrowStorageExceptions(): void
    {
        $database = new FakeSessionDatabaseDriver();
        $database->seedRaw('corrupt', 'COMMONPHPSESSID', 'not serialized', time());
        $driver = new CommonPHPDatabaseSessionDriver(
            $database,
            new CommonPHPDatabaseSessionOptions(gcProbability: 0),
            sessionId: 'corrupt',
        );

        $this->expectException(CommonPHPDatabaseSessionStorageException::class);

        $driver->start();
    }
}

final class FakeSessionDatabaseDriver extends AbstractDatabaseDriver
{
    /**
     * @var array<string, array{id: string, name: string, payload: string, last_activity: int}>
     */
    private array $rows = [];

    /**
     * @var list<array{action: string, query: string, parameters: array<string|int, mixed>}>
     */
    public array $log = [];

    public function getName(): string
    {
        return 'fake-session-database';
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function seed(
        string $id,
        string $name,
        array $payload,
        int $lastActivity,
        ?SessionPayloadSerializer $serializer = null,
    ): void {
        $serializer ??= new SessionPayloadSerializer();
        $this->seedRaw($id, $name, $serializer->encode($payload), $lastActivity);
    }

    public function seedRaw(string $id, string $name, string $payload, int $lastActivity): void
    {
        $this->rows[$this->key($id, $name)] = [
            'id' => $id,
            'name' => $name,
            'payload' => $payload,
            'last_activity' => $lastActivity,
        ];
    }

    public function has(string $id, string $name): bool
    {
        return array_key_exists($this->key($id, $name), $this->rows);
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(string $id, string $name, SessionPayloadSerializer $serializer): array
    {
        $row = $this->rows[$this->key($id, $name)] ?? null;

        if ($row === null) {
            throw new RuntimeException('Missing fake session row.');
        }

        return $serializer->decode($row['payload']);
    }

    public function execute(string $query, array $parameters = []): int|bool
    {
        $this->log[] = ['action' => 'execute', 'query' => $query, 'parameters' => $parameters];
        $normalized = $this->normalize($query);

        if ($normalized === 'insert into sessions (id, name, payload, last_activity) values (:id, :name, :payload, :lastactivity)') {
            $this->seedRaw(
                (string) $parameters['id'],
                (string) $parameters['name'],
                (string) $parameters['payload'],
                (int) $parameters['lastActivity'],
            );

            return 1;
        }

        if ($normalized === 'update sessions set payload = :payload, last_activity = :lastactivity where id = :id and name = :name') {
            $key = $this->key((string) $parameters['id'], (string) $parameters['name']);

            if (!array_key_exists($key, $this->rows)) {
                return 0;
            }

            $this->rows[$key]['payload'] = (string) $parameters['payload'];
            $this->rows[$key]['last_activity'] = (int) $parameters['lastActivity'];

            return 1;
        }

        if ($normalized === 'delete from sessions where last_activity <= :expiredbefore') {
            $deleted = 0;

            foreach ($this->rows as $key => $row) {
                if ($row['last_activity'] <= (int) $parameters['expiredBefore']) {
                    unset($this->rows[$key]);
                    ++$deleted;
                }
            }

            return $deleted;
        }

        if ($normalized === 'delete from sessions where id = :id and name = :name') {
            $key = $this->key((string) $parameters['id'], (string) $parameters['name']);
            $existed = array_key_exists($key, $this->rows);
            unset($this->rows[$key]);

            return $existed ? 1 : 0;
        }

        throw new RuntimeException('Unsupported fake execute query: ' . $query);
    }

    public function fetchOne(string $query, array $parameters = []): array|false
    {
        $this->log[] = ['action' => 'fetch one', 'query' => $query, 'parameters' => $parameters];

        if ($this->normalize($query) !== 'select id, name, payload, last_activity from sessions where id = :id and name = :name') {
            throw new RuntimeException('Unsupported fake fetch query: ' . $query);
        }

        return $this->rows[$this->key((string) $parameters['id'], (string) $parameters['name'])] ?? false;
    }

    public function fetchAll(
        string $query,
        array $parameters = [],
        FetchMode $fetchMode = FetchMode::FETCH_ASSOC,
    ): array {
        return [];
    }

    public function beginTransaction(): void
    {
    }

    public function commit(): void
    {
    }

    public function rollBack(): void
    {
    }

    public function lastInsertId(): string|false
    {
        return false;
    }

    public function ping(): bool
    {
        return true;
    }

    private function key(string $id, string $name): string
    {
        return $name . "\0" . $id;
    }

    private function normalize(string $query): string
    {
        return strtolower(trim(preg_replace('/\s+/', ' ', $query) ?? $query));
    }
}
