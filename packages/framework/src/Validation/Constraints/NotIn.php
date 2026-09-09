<?php

declare(strict_types=1);

namespace Kinetis\Validation\Constraints;

use Kinetis\Validation\Constraint;
use Kinetis\Validation\Violation;
use Attribute;
use InvalidArgumentException;

/**
 * Exclusion from a closed set of scalar values, published as the
 * negation of JSON Schema's own `enum`.
 *
 * The set is checked at construction rather than trusted, for the same
 * reasons {@see In}'s is: it leaves this class as the `enum` of a
 * generated document and as the `choices` parameter of a violation, and
 * both destinations are JSON. An empty set excludes nothing and is not a
 * valid `enum`; a non-scalar member has no JSON spelling and no
 * `in_array()` identity a wire value could match; a non-finite float is
 * unencodable.
 *
 * Membership is strict, so a value of a different type is not excluded:
 * `#[NotIn(['1'])]` refuses the string `'1'` and admits the integer `1`.
 */
#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)]
final readonly class NotIn implements Constraint
{
    /**
     * @param list<scalar> $choices
     */
    public function __construct(
        private array $choices,
    ) {
        if ($choices === [] || !array_is_list($choices)) {
            throw new InvalidArgumentException('NotIn choices must be a non-empty list of scalars.');
        }

        foreach ($choices as $choice) {
            if (!is_scalar($choice)) {
                throw new InvalidArgumentException(
                    'NotIn choices must be scalars, ' . get_debug_type($choice) . ' given.',
                );
            }

            if (is_float($choice) && !is_finite($choice)) {
                throw new InvalidArgumentException(
                    'NotIn choices must be finite numbers, ' . var_export($choice, true) . ' given.',
                );
            }
        }
    }

    #[\Override]
    public function validate(mixed $value): ?Violation
    {
        if (!in_array($value, $this->choices, true)) {
            return null;
        }

        return new Violation(
            [],
            'not_in',
            'must not be one of: ' . implode(', ', array_map('strval', $this->choices)) . '.',
            ['choices' => $this->choices],
        );
    }

    #[\Override]
    public function schema(): array
    {
        return ['not' => ['enum' => $this->choices]];
    }
}
