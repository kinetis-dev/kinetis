<?php

declare(strict_types=1);

namespace Kinetis\Tests\Validation\Fixtures;

use Kinetis\Validation\Constraint;
use Kinetis\Validation\Violation;
use Attribute;

/**
 * An application's own rule, defined entirely outside the framework and
 * registered nowhere. It owns both halves of its contract: the violation
 * a broken value gets, and the JSON Schema keyword that states the same
 * rule to a client — `pattern` holding the undelimited ECMA-262
 * expression JSON Schema requires, equivalent to the PCRE checked below.
 */
#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)]
final readonly class Uppercase implements Constraint
{
    #[\Override]
    public function validate(mixed $value): ?Violation
    {
        if (!is_string($value) || preg_match('/^[A-Z]+$/', $value) !== 1) {
            return new Violation([], 'uppercase', 'must be all uppercase letters.');
        }

        return null;
    }

    #[\Override]
    public function schema(): array
    {
        return ['pattern' => '^[A-Z]+$'];
    }
}
