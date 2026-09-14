<?php

declare(strict_types=1);

namespace Kinetis\Orm\Attributes;

use Attribute;

/**
 * Marks the identifier property. Without it, the property named exactly
 * `id` is the identifier.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class Id {}
