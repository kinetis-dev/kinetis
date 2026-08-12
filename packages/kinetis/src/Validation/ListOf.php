<?php

declare(strict_types=1);

namespace Kinetis\Validation;

use Attribute;

/**
 * Declares a constructor parameter typed `array` as a list of DTOs of the
 * given class — PHP's `array` type carries no element-type information for
 * Hydrator to reflect on otherwise. Hydrator hydrates each array-shaped
 * element the same way it hydrates a single nested DTO parameter; any
 * element that isn't itself an array is left unchanged, mirroring how a
 * single nested DTO parameter treats a non-array value.
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class ListOf
{
    /**
     * @param class-string $class
     */
    public function __construct(
        private string $class,
    ) {}

    /**
     * @return class-string
     */
    public function itemClass(): string
    {
        return $this->class;
    }
}
