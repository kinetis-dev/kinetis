<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http\Fixtures;

final readonly class UserPreferencesRequest
{
    public function __construct(
        public ?string $theme = null,
        public bool $notificationsEnabled = true,
    ) {}
}
