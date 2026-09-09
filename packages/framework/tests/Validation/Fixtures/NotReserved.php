<?php

declare(strict_types=1);

namespace Kinetis\Tests\Validation\Fixtures;

use Kinetis\Validation\Constraint;
use Kinetis\Validation\Violation;
use Attribute;

/**
 * An application rule with no JSON Schema equivalent — a fixed reject
 * list is not a keyword JSON Schema spells — so it publishes nothing and
 * leaves the schema of whatever it guards untouched, exactly as
 * #[NotBlank] and #[Regex] do.
 */
#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)]
final readonly class NotReserved implements Constraint
{
    private const array RESERVED = ['ROOT', 'ADMIN'];

    #[\Override]
    public function validate(mixed $value): ?Violation
    {
        if (!in_array($value, self::RESERVED, true)) {
            return null;
        }

        return new Violation([], 'not_reserved', 'must not be a reserved name.', ['reserved' => self::RESERVED]);
    }

    #[\Override]
    public function schema(): array
    {
        return [];
    }
}
