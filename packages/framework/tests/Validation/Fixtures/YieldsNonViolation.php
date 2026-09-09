<?php

declare(strict_types=1);

namespace Kinetis\Tests\Validation\Fixtures;

use Attribute;
use Kinetis\Validation\ObjectConstraint;
use Kinetis\Validation\ValidationContext;

#[Attribute(Attribute::TARGET_CLASS)]
final readonly class YieldsNonViolation implements ObjectConstraint
{
    /**
     * A rule about the object as a whole: it names no field, so there is
     * nothing for the collector to check against the constructor.
     */
    #[\Override]
    public function fields(): array
    {
        return [];
    }

    #[\Override]
    public function validate(object $value, ValidationContext $context): iterable
    {
        yield 'not a violation';
    }

    #[\Override]
    public function schema(): array
    {
        return [];
    }
}
