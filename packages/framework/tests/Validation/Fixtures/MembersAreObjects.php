<?php

declare(strict_types=1);

namespace Kinetis\Tests\Validation\Fixtures;

use Kinetis\Validation\Constraint;
use Kinetis\Validation\Violation;
use Attribute;
use stdClass;

/**
 * Passes only a map whose every member is a stdClass, so a test can tell
 * whether a rule saw nested JSON objects as objects or as arrays.
 */
#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)]
final readonly class MembersAreObjects implements Constraint
{
    #[\Override]
    public function validate(mixed $value): ?Violation
    {
        if (!is_array($value) || array_filter($value, static fn (mixed $member): bool => !$member instanceof stdClass) !== []) {
            return new Violation([], 'members_are_objects', 'must hold only objects.');
        }

        return null;
    }

    #[\Override]
    public function schema(): array
    {
        return [];
    }
}
