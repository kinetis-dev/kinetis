<?php

declare(strict_types=1);

namespace Kinetis\Broadcasting\Tests\Fixtures;

use Kinetis\Broadcasting\Attributes\BroadcastChannel;
use Kinetis\Http\CurrentUserInterface;

/**
 * Returns presence data with no `user_id` at all — a real application
 * authorizer bug, standing in for whatever a real one might return
 * incorrectly.
 */
final class MalformedPresenceAuthorizer
{
    /**
     * @return array{user_info: array{name: string}}
     */
    #[BroadcastChannel('malformed.{id}')]
    public function authorize(CurrentUserInterface $user, string $id): array
    {
        return ['user_info' => ['name' => 'Ada']];
    }
}
