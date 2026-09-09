<?php

declare(strict_types=1);

namespace Kinetis\Tests\Validation\Fixtures;

use Kinetis\Validation\Constraints\MinItems;
use Kinetis\Validation\Each;
use Kinetis\Validation\ListOf;

/**
 * Rules at both levels of one field: #[MinItems] counts the list,
 * while each #[Each] describes one element — one framework rule, one
 * application rule taking a positional and a named argument, and one
 * about an enum case rather than the integer behind it.
 */
final readonly class EachRulesRequest
{
    public function __construct(
        #[MinItems(2)]
        #[ListOf('string')]
        #[Each(Uppercase::class)]
        #[Each(LengthBetween::class, 2, max: 4)]
        public array $codes,
        #[ListOf(Priority::class)]
        #[Each(MinimumPriority::class, least: 2)]
        public array $escalations = [],
    ) {}
}
