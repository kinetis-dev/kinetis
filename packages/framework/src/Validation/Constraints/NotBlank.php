<?php

declare(strict_types=1);

namespace Kinetis\Validation\Constraints;

use Kinetis\Validation\Constraint;
use Kinetis\Validation\Violation;
use Attribute;

/**
 * A string carrying something other than whitespace.
 *
 * Runtime-only: no JSON Schema keyword carries trim-aware blank-string
 * semantics — `minLength: 1` rejects `""` but admits `"   "` — so
 * publishing one would state a rule the request is not checked against.
 */
#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)]
final readonly class NotBlank implements Constraint
{
    #[\Override]
    public function validate(mixed $value): ?Violation
    {
        if (!is_string($value) || trim($value) === '') {
            return new Violation([], 'not_blank', 'must not be blank.');
        }

        return null;
    }

    #[\Override]
    public function schema(): array
    {
        return [];
    }
}
