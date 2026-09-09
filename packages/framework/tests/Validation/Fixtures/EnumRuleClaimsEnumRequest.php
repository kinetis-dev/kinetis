<?php

declare(strict_types=1);

namespace Kinetis\Tests\Validation\Fixtures;

final readonly class EnumRuleClaimsEnumRequest
{
    public function __construct(
        #[ClaimsKeyword('enum', ['1'])]
        public Priority $priority,
    ) {}
}
