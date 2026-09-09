<?php

declare(strict_types=1);

namespace Kinetis\Tests\Validation\Fixtures;

use Kinetis\Validation\ListOf;

final readonly class EmptyBackedEnumListRequest
{
    public function __construct(
        #[ListOf(NoCases::class)]
        public array $choices,
    ) {}
}
