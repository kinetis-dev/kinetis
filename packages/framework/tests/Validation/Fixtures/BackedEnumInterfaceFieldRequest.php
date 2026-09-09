<?php

declare(strict_types=1);

namespace Kinetis\Tests\Validation\Fixtures;

use BackedEnum;

/**
 * The BackedEnum interface itself, not an enum backed by anything. It
 * has no cases to admit — its inherited cases() is abstract — so the
 * field keeps the instance-only shape every interface-typed field has.
 */
final readonly class BackedEnumInterfaceFieldRequest
{
    public function __construct(
        public BackedEnum $choice,
    ) {}
}
