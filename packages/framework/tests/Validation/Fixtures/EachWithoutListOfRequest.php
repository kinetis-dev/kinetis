<?php

declare(strict_types=1);

namespace Kinetis\Tests\Validation\Fixtures;

use Kinetis\Validation\Each;

final readonly class EachWithoutListOfRequest
{
    public function __construct(
        #[Each(Uppercase::class)]
        public string $code,
    ) {}
}
