<?php

declare(strict_types=1);

namespace Kinetis\Validation\Constraints;

use Kinetis\Validation\Constraint;
use Attribute;

#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)]
final readonly class Uuid implements Constraint
{
    private const string PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';

    #[\Override]
    public function validate(mixed $value): ?string
    {
        if (!is_string($value) || preg_match(self::PATTERN, $value) !== 1) {
            return 'must be a valid UUID.';
        }

        return null;
    }
}
