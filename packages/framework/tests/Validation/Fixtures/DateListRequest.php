<?php

declare(strict_types=1);

namespace Kinetis\Tests\Validation\Fixtures;

use Kinetis\Validation\Constraints\Date;
use Kinetis\Validation\Each;
use Kinetis\Validation\ListOf;

final readonly class DateListRequest
{
    public function __construct(
        #[ListOf('string')]
        #[Each(Date::class)]
        public array $dates,
    ) {}
}
