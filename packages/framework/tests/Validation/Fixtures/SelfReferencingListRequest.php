<?php

declare(strict_types=1);

namespace Kinetis\Tests\Validation\Fixtures;

use Kinetis\Validation\ListOf;

final readonly class SelfReferencingListRequest
{
    public function __construct(
        public string $label,
        #[ListOf(SelfReferencingListRequest::class)]
        public array $children = [],
    ) {}
}
