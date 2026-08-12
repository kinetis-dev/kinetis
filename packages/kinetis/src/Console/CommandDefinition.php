<?php

declare(strict_types=1);

namespace Kinetis\Console;

final class CommandDefinition
{
    public function __construct(
        public readonly string $name,
        public readonly string $description,
        public readonly string $controllerClass,
        public readonly string $controllerMethod,
        public readonly bool $takesArguments,
    ) {}
}
