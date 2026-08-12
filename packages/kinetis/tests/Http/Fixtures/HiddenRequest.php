<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http\Fixtures;

final readonly class HiddenRequest
{
    public function __construct(
        public bool $ok,
    ) {}
}
