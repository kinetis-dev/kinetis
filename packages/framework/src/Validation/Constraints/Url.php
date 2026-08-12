<?php

declare(strict_types=1);

namespace Kinetis\Validation\Constraints;

use Kinetis\Validation\Constraint;
use Attribute;

#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)]
final readonly class Url implements Constraint
{
    #[\Override]
    public function validate(mixed $value): ?string
    {
        if (!is_string($value) || filter_var($value, FILTER_VALIDATE_URL) === false) {
            return 'must be a valid URL.';
        }

        return null;
    }
}
