<?php

declare(strict_types=1);

namespace Kinetis\Authorization\Exception;

use RuntimeException;

final class AuthorizationException extends RuntimeException
{
    public static function denied(string $message): self
    {
        return new self($message);
    }
}
