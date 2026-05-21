<?php

declare(strict_types=1);

namespace CommonPHP\Drivers\Session\CommonPHPDatabase;

use CommonPHP\Drivers\Session\CommonPHPDatabase\Exceptions\CommonPHPDatabaseSessionStorageException;
use Throwable;

final readonly class SessionPayloadSerializer
{
    /**
     * @param bool|array<class-string> $allowedClasses
     */
    public function __construct(
        private bool|array $allowedClasses = true,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function encode(array $payload): string
    {
        try {
            return serialize($payload);
        } catch (Throwable $throwable) {
            throw CommonPHPDatabaseSessionStorageException::forSessionOperation('encode payload', previous: $throwable);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function decode(string $payload): array
    {
        if ($payload === '') {
            return [];
        }

        $warning = null;

        set_error_handler(
            static function (int $severity, string $message) use (&$warning): bool {
                $warning = $message;

                return true;
            },
        );

        try {
            $decoded = unserialize($payload, ['allowed_classes' => $this->allowedClasses]);
        } catch (Throwable $throwable) {
            throw CommonPHPDatabaseSessionStorageException::forCorruptPayload($throwable);
        } finally {
            restore_error_handler();
        }

        if ($warning !== null) {
            throw CommonPHPDatabaseSessionStorageException::forCorruptPayload();
        }

        if (!is_array($decoded)) {
            throw CommonPHPDatabaseSessionStorageException::forUnexpectedPayload($decoded);
        }

        return $decoded;
    }
}
