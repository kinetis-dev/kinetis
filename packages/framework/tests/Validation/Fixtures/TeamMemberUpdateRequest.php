<?php

declare(strict_types=1);

namespace Kinetis\Tests\Validation\Fixtures;

use Kinetis\Validation\Absent;
use Kinetis\Validation\ObjectConstraints\AtLeastOneProvided;

/**
 * A nested DTO carrying its own object rule, so a failure it raises has
 * to arrive under the owning field's path rather than at the root.
 */
#[AtLeastOneProvided('role')]
final readonly class TeamMemberUpdateRequest
{
    public function __construct(
        public string|Absent $role = Absent::Value,
    ) {}
}
