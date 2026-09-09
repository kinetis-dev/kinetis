<?php

declare(strict_types=1);

namespace Kinetis\Tests\Validation\Fixtures;

use Attribute;
use Kinetis\Validation\ObjectConstraint;
use Kinetis\Validation\ValidationContext;
use Kinetis\Validation\Violation;

/**
 * Reports which of the named fields the context said were supplied,
 * as the violation's own `fields` parameter. Its subject is the
 * ValidationContext a rule actually receives, not the object — so a test
 * can assert on presence itself rather than on some other rule's verdict.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class RecordsSuppliedFields implements ObjectConstraint
{
    /** @var list<string> */
    private array $fields;

    public function __construct(string ...$fields)
    {
        $this->fields = array_values($fields);
    }

    /**
     * The names this rule asks the context about, which the collector
     * checks are real constructor fields of the class it guards.
     *
     * @return list<string>
     */
    #[\Override]
    public function fields(): array
    {
        return $this->fields;
    }

    #[\Override]
    public function validate(object $value, ValidationContext $context): iterable
    {
        yield new Violation([], 'supplied', 'reports supplied fields.', [
            'fields' => array_values(array_filter(
                $this->fields,
                static fn (string $field): bool => $context->wasSupplied($field),
            )),
        ]);
    }

    #[\Override]
    public function schema(): array
    {
        return [];
    }
}
