<?php

declare(strict_types=1);

namespace Kinetis\Tests\Validation\Fixtures;

use Kinetis\Validation\ListOf;

final readonly class ListOfNothingRequest
{
    public function __construct(
        #[ListOf('')]
        public array $nothing,
    ) {}
}
