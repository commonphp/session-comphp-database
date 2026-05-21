<?php

declare(strict_types=1);

namespace CommonPHP\Drivers\Session\CommonPHPDatabase\Exceptions;

use CommonPHP\Session\Exceptions\SessionStorageException;
use Throwable;

class CommonPHPDatabaseSessionStorageException extends SessionStorageException
{
    public static function forSessionOperation(
        string $operation,
        ?string $sessionId = null,
        ?Throwable $previous = null,
    ): self {
        $target = $sessionId === null ? '' : ' for session "' . $sessionId . '"';

        return new self(
            'CommonPHP database session operation "' . $operation . '" failed' . $target . '.',
            previous: $previous,
        );
    }

    public static function forCorruptPayload(?Throwable $previous = null): self
    {
        return new self('CommonPHP database session payload could not be decoded.', previous: $previous);
    }

    public static function forUnexpectedPayload(mixed $payload): self
    {
        return new self(
            'CommonPHP database session payload decoded to ' . get_debug_type($payload) . ' instead of array.',
        );
    }
}
