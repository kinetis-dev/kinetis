<?php

declare(strict_types=1);

namespace Kinetis\Validation;

/**
 * Implemented by every constraint attribute (Email, MinLength, ...).
 * Hydrator finds them via getAttributes(Constraint::class,
 * ReflectionAttribute::IS_INSTANCEOF) the same way Router finds
 * RouteAttribute instances, so adding a new constraint never requires
 * touching the Hydrator.
 */
interface Constraint
{
    /**
     * @return string|null an error message, or null if $value is valid
     */
    public function validate(mixed $value): ?string;
}
