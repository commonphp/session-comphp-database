<?php

declare(strict_types=1);

namespace CommonPHP\Drivers\Session\CommonPHPDatabase;

use CommonPHP\Database\Contracts\DatabaseDriverInterface;
use CommonPHP\Database\Contracts\DatabaseInterface;
use CommonPHP\Drivers\Session\CommonPHPDatabase\Exceptions\CommonPHPDatabaseSessionStorageException;
use Throwable;

final readonly class SessionGarbageCollector
{
    public function __construct(
        private DatabaseInterface|DatabaseDriverInterface $database,
        private CommonPHPDatabaseSessionOptions $options,
    ) {
    }

    public function collect(?int $now = null): int
    {
        $now ??= time();
        $expiredBefore = $now - $this->options->lifetimeSeconds;

        try {
            $deleted = $this->database instanceof DatabaseInterface
                ? $this->database->execute(
                    $this->options->deleteExpiredSql(),
                    ['expiredBefore' => $expiredBefore],
                    $this->options->connection,
                )
                : $this->database->execute(
                    $this->options->deleteExpiredSql(),
                    ['expiredBefore' => $expiredBefore],
                );
        } catch (Throwable $throwable) {
            throw CommonPHPDatabaseSessionStorageException::forSessionOperation(
                'collect garbage',
                previous: $throwable,
            );
        }

        if ($deleted === false) {
            throw CommonPHPDatabaseSessionStorageException::forSessionOperation('collect garbage');
        }

        return is_int($deleted) ? $deleted : 0;
    }
}
