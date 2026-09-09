<?php

declare(strict_types=1);

namespace Kinetis\Validation\Constraints;

use Kinetis\Validation\Constraint;
use Kinetis\Validation\Violation;
use Attribute;
use InvalidArgumentException;

/**
 * Membership in a closed set of scalar values, published as JSON
 * Schema's own `enum`.
 *
 * The set is checked at construction rather than trusted, because it
 * leaves this class twice — as the `enum` of a generated document and
 * as the `choices` parameter of a violation — and both destinations are
 * JSON. An empty set admits no value at all and is not a valid `enum`;
 * a non-scalar member has no JSON spelling `enum` could carry and no
 * `in_array()` identity a wire value could match; a non-finite float is
 * unencodable, which `Kinetis\Validation\Violation` would refuse later,
 * at the transport edge rather than at the declaration that caused it.
 */
#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)]
final readonly class In implements Constraint
{
    /**
     * @param list<scalar> $choices
     */
    public function __construct(
        private array $choices,
    ) {
        if ($choices === [] || !array_is_list($choices)) {
            throw new InvalidArgumentException('In choices must be a non-empty list of scalars.');
        }

        foreach ($choices as $choice) {
            if (!is_scalar($choice)) {
                throw new InvalidArgumentException(
                    'In choices must be scalars, ' . get_debug_type($choice) . ' given.',
                );
            }

            if (is_float($choice) && !is_finite($choice)) {
                throw new InvalidArgumentException(
                    'In choices must be finite numbers, ' . var_export($choice, true) . ' given.',
                );
            }
        }
    }

    #[\Override]
    public function validate(mixed $value): ?Violation
    {
        if (in_array($value, $this->choices, true)) {
            return null;
        }

        return new Violation(
            [],
            'in',
            'must be one of: ' . implode(', ', array_map('strval', $this->choices)) . '.',
            ['choices' => $this->choices],
        );
    }

    #[\Override]
    public function schema(): array
    {
        return ['enum' => $this->choices];
    }
}
