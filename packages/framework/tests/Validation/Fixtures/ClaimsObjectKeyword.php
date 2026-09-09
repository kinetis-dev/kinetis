<?php

declare(strict_types=1);

namespace Kinetis\Tests\Validation\Fixtures;

use Attribute;
use Kinetis\Validation\ObjectConstraint;
use Kinetis\Validation\ValidationContext;

/**
 * An object rule that contributes whatever object-level keyword its
 * declaration names — pointed at one the PHP declaration owns, or at one
 * a second rule already claimed, it is the declaration JsonSchema has to
 * refuse for the same reason a field rule restating `type` is refused.
 *
 * @see ClaimsKeyword, its field-level counterpart
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final readonly class ClaimsObjectKeyword implements ObjectConstraint
{
    /**
     * @param array<string, mixed>|string|bool $value
     */
    public function __construct(
        private string $keyword,
        private array|string|bool $value,
    ) {}

    /**
     * A rule about the object as a whole: it names no field, so there is
     * nothing for the collector to check against the constructor.
     */
    #[\Override]
    public function fields(): array
    {
        return [];
    }

    #[\Override]
    public function validate(object $value, ValidationContext $context): iterable
    {
        return [];
    }

    #[\Override]
    public function schema(): array
    {
        return [$this->keyword => $this->value];
    }
}
