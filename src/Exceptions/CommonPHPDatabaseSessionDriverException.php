<?php

declare(strict_types=1);

namespace CommonPHP\Drivers\Session\CommonPHPDatabase\Exceptions;

use CommonPHP\Session\Exceptions\SessionDriverException;
use Throwable;

class CommonPHPDatabaseSessionDriverException extends SessionDriverException
{
    public static function invalidOption(string $option, string $message): self
    {
        return new self('Invalid CommonPHP database session option "' . $option . '": ' . $message);
    }

    public static function forRandomId(Throwable $previous): self
    {
        return self::forRandomBytes('generating a database session id', $previous);
    }

    public static function forRandomBytes(string $operation, Throwable $previous): self
    {
        return new self(
            'Unable to read secure random data while ' . $operation . '.',
            previous: $previous,
        );
    }
}
