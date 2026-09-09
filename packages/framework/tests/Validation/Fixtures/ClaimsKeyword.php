<?php

declare(strict_types=1);

namespace Kinetis\Tests\Validation\Fixtures;

use Kinetis\Validation\Constraint;
use Kinetis\Validation\Violation;
use Attribute;

/**
 * An application rule that contributes whatever JSON Schema keyword its
 * declaration names. Pointed at a keyword the PHP declaration itself
 * owns — `type` for a scalar, `items` for a #[ListOf] list — it is the
 * declaration JsonSchema has to refuse: the runtime check reads the
 * PHP type, never this, so a rule allowed to restate the shape would
 * publish one the request is not held to.
 */
#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)]
final readonly class ClaimsKeyword implements Constraint
{
    /**
     * @param array<string, mixed>|string $value
     */
    public function __construct(
        private string $keyword,
        private array|string $value,
    ) {}

    #[\Override]
    public function validate(mixed $value): ?Violation
    {
        return null;
    }

    #[\Override]
    public function schema(): array
    {
        return [$this->keyword => $this->value];
    }
}
