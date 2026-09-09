<?php

declare(strict_types=1);

namespace Kinetis\Tests\Validation\Fixtures;

use Kinetis\Validation\ListOf;

/**
 * Owns {@see TeamMemberUpdateRequest} twice — once directly, once as a
 * list element — so both prefixing paths a nested object rule can take
 * are visible on one DTO.
 */
final readonly class TeamUpdateRequest
{
    /**
     * @param list<TeamMemberUpdateRequest> $members
     */
    public function __construct(
        public TeamMemberUpdateRequest $lead,
        #[ListOf(TeamMemberUpdateRequest::class)]
        public array $members,
    ) {}
}
