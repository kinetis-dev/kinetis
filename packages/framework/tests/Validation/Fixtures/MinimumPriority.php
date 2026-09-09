<?php

declare(strict_types=1);

namespace Kinetis\Tests\Validation\Fixtures;

use Kinetis\Validation\Constraint;
use Kinetis\Validation\Violation;
use Attribute;

/**
 * An application rule about a Priority case, not about the integer it
 * is backed by: a rule reading a raw backing value would report
 * `not_a_priority` for every value the enum resolved correctly.
 *
 * Its keyword describes the wire value the rule's own check implies —
 * a case at least this priority is a backing value at least this
 * large — which is the half of the contract a document states.
 */
#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)]
final readonly class MinimumPriority implements Constraint
{
    public function __construct(
        private int $least,
    ) {}

    #[\Override]
    public function validate(mixed $value): ?Violation
    {
        if (!$value instanceof Priority) {
            return new Violation([], 'not_a_priority', 'must be a priority.');
        }

        if ($value->value >= $this->least) {
            return null;
        }

        return new Violation(
            [],
            'minimum_priority',
            "must be at least priority {$this->least}.",
            ['least' => $this->least],
        );
    }

    #[\Override]
    public function schema(): array
    {
        return ['minimum' => $this->least];
    }
}
