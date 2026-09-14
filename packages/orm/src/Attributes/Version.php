<?php

declare(strict_types=1);

namespace Kinetis\Orm\Attributes;

use Attribute;

/**
 * Marks the version property that opts an entity into optimistic locking:
 * at most one per entity, typed `int`, and not the identifier. A property
 * named `version` without this attribute is ordinary mapped data.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final readonly class Version {}
