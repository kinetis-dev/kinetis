<?php

declare(strict_types=1);

namespace Kinetis\Tests\Validation\Fixtures;

use Kinetis\Validation\ObjectMap;

final readonly class KeptObjectMapRuleRequest
{
    public function __construct(
        #[ObjectMap(keepObjects: true)]
        #[MembersAreObjects]
        public array $settings,
    ) {}
}
