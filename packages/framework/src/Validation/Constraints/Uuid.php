<?php

declare(strict_types=1);

namespace Kinetis\Validation\Constraints;

use Kinetis\Validation\Constraint;
use Kinetis\Validation\Violation;
use Attribute;

/**
 * A UUID in the string form RFC 9562 defines: 32 hexadecimal digits in
 * 8-4-4-4-12 groups, in either case. That is the whole contract.
 *
 * Version and variant are deliberately not policy here. They are fields
 * of the identifier, not of its spelling, and a rule reading them would
 * reject values RFC 9562 defines and applications issue — the nil and
 * max UUIDs, and versions 6, 7 and 8 — for identifiers a client has no
 * way to change. `format: uuid` states the same string form, so the
 * published document and the check agree.
 *
 * The anchors carry PCRE's `D` modifier: a trailing newline is not part
 * of an identifier.
 */
#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)]
final readonly class Uuid implements Constraint
{
    private const string PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/Di';

    #[\Override]
    public function validate(mixed $value): ?Violation
    {
        if (!is_string($value) || preg_match(self::PATTERN, $value) !== 1) {
            return new Violation([], 'uuid', 'must be a valid UUID.');
        }

        return null;
    }

    #[\Override]
    public function schema(): array
    {
        return ['format' => 'uuid'];
    }
}
