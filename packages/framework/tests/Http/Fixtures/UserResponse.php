<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http\Fixtures;

final readonly class UserResponse
{
    public function __construct(
        public string $name,
        public string $email,
    ) {}
}
