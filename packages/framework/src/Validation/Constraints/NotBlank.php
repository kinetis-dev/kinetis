<?php

declare(strict_types=1);

namespace Kinetis\Validation\Constraints;

use Kinetis\Validation\Constraint;
use Attribute;

#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)]
final readonly class NotBlank implements Constraint
{
    #[\Override]
    public function validate(mixed $value): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            return 'must not be blank.';
        }

        return null;
    }
}
