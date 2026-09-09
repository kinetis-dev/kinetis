<?php

declare(strict_types=1);

namespace Kinetis\Tests\Validation\Fixtures;

use Kinetis\Validation\ListOf;
use BackedEnum;

final readonly class ListOfTheBackedEnumInterfaceRequest
{
    public function __construct(
        #[ListOf(BackedEnum::class)]
        public array $choices,
    ) {}
}
