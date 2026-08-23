<?php

declare(strict_types=1);

namespace Kinetis\Validation\Exception;

use RuntimeException;

final class ValidationException extends RuntimeException
{
    /**
     * @param array<string,list<string>> $errors
     */
    private function __construct(
        public readonly array $errors,
    ) {
        parent::__construct('Validation failed.');
    }

    /**
     * @param array<string,list<string>> $errors
     */
    public static function forErrors(array $errors): self
    {
        return new self($errors);
    }
}
