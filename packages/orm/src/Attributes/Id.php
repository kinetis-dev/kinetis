<?php

declare(strict_types=1);

namespace Kinetis\Orm\Attributes;

use Attribute;

/**
 * Marks the identifier property. Without it, the property named exactly
 * `id` is the identifier. An identifier is assigned by the application
 * unless $generated leaves it to the database, which requires a property
 * typed `?int`.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final readonly class Id
{
    public function __construct(public bool $generated = false) {}
}
