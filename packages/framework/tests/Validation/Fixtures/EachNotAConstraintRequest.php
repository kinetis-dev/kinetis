<?php

declare(strict_types=1);

namespace Kinetis\Tests\Validation\Fixtures;

use Kinetis\Validation\Each;
use Kinetis\Validation\ListOf;

final readonly class EachNotAConstraintRequest
{
    public function __construct(
        #[ListOf('string')]
        #[Each(OrderItem::class)]
        public array $tags,
    ) {}
}
