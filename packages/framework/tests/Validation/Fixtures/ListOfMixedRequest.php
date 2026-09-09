<?php

declare(strict_types=1);

namespace Kinetis\Tests\Validation\Fixtures;

use Kinetis\Validation\ListOf;

final readonly class ListOfMixedRequest
{
    public function __construct(
        #[ListOf('mixed')]
        public array $anything,
    ) {}
}
