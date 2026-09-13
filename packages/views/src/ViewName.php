<?php

declare(strict_types=1);

namespace Kinetis\Views;

use InvalidArgumentException;

final readonly class ViewName
{
    public function __construct(public string $value)
    {
        if ($value === '' || $value[0] === '/' || str_contains($value, '\\')
            || preg_match('/[\x00-\x1F\x7F]/', $value) === 1
            || preg_match('/\.(?:php|latte|twig)$/Di', $value) === 1) {
            throw new InvalidArgumentException(
                "A view name must be extensionless and relative, such as 'articles/index'.",
            );
        }

        foreach (explode('/', $value) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new InvalidArgumentException('A view name cannot contain empty or traversal segments.');
            }
        }
    }

    public function file(string $extension): string
    {
        if (preg_match('/^[a-z][a-z0-9]*$/D', $extension) !== 1) {
            throw new InvalidArgumentException("Invalid view extension '{$extension}'.");
        }

        return $this->value . '.' . $extension;
    }
}
