<?php

declare(strict_types=1);

namespace Kinetis\Broadcasting\Tests\Fixtures;

use Kinetis\Broadcasting\Attributes\BroadcastChannel;
use Kinetis\Http\CurrentUserInterface;

final class TeamPresenceAuthorizer
{
    /**
     * @return array{user_id: string|int}
     */
    #[BroadcastChannel('team.{teamId}')]
    public function authorizeTeam(CurrentUserInterface $user, string $teamId): array
    {
        return ['user_id' => $user->id()];
    }
}
