<?php

declare(strict_types=1);

namespace Kinetis\Validation\ObjectConstraints;

use Attribute;
use InvalidArgumentException;
use Kinetis\Validation\ObjectConstraint;
use Kinetis\Validation\ValidationContext;
use Kinetis\Validation\Violation;

/**
 * At least one of the named fields must have been supplied by the input
 * — the rule an update DTO needs so an empty body is a client error
 * rather than a silent no-op write.
 *
 * Presence is the whole question: the values themselves are never read,
 * so a field explicitly sent as `null` counts as supplied wherever the
 * declaration accepts `null`, and a field left out counts as missing
 * even when its default happens to look like a value. See
 * {@see ValidationContext}.
 *
 * The field list is checked at construction rather than trusted,
 * because it leaves this class twice — as the `fields` parameter of a
 * violation and as the `anyOf` of a generated schema. An empty list
 * states nothing; an empty name matches no constructor parameter; a
 * repeated name would publish a duplicated `anyOf` branch that narrows
 * nothing.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final readonly class AtLeastOneProvided implements ObjectConstraint
{
    /** @var non-empty-list<string> */
    private array $fields;

    public function __construct(string ...$fields)
    {
        $fields = array_values($fields);

        if ($fields === []) {
            throw new InvalidArgumentException('AtLeastOneProvided needs at least one field name.');
        }

        if (in_array('', $fields, true)) {
            throw new InvalidArgumentException('AtLeastOneProvided field names must not be empty.');
        }

        if (count(array_unique($fields)) !== count($fields)) {
            throw new InvalidArgumentException(
                'AtLeastOneProvided field names must be distinct: ' . implode(', ', $fields) . '.',
            );
        }

        $this->fields = $fields;
    }

    /**
     * Every name this rule was given: each is a field it asks presence
     * about and publishes a `required` clause for, so
     * {@see \Kinetis\Validation\Hydrator} rejects one the DTO's
     * constructor does not declare — an unsatisfiable `anyOf` under a
     * closed object would turn every valid request into a client failure.
     *
     * @return non-empty-list<string>
     */
    #[\Override]
    public function fields(): array
    {
        return $this->fields;
    }

    #[\Override]
    public function validate(object $value, ValidationContext $context): iterable
    {
        foreach ($this->fields as $field) {
            if ($context->wasSupplied($field)) {
                return;
            }
        }

        // The payload as a whole is what fell short, not any one of the
        // fields — none of them is individually required.
        yield new Violation(
            [],
            'at_least_one_provided',
            'must provide at least one of: ' . implode(', ', $this->fields) . '.',
            ['fields' => $this->fields],
        );
    }

    /**
     * `anyOf` over one `required` clause per field is exactly what this
     * checks: JSON Schema's `required` is presence of the member, the
     * same question {@see ValidationContext} answers, and a union of
     * single-member requirements admits precisely the objects naming at
     * least one of them.
     */
    #[\Override]
    public function schema(): array
    {
        return ['anyOf' => array_map(static fn (string $field): array => ['required' => [$field]], $this->fields)];
    }
}
