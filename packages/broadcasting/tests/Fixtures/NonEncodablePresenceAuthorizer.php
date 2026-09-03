<?php

declare(strict_types=1);

namespace Kinetis\Broadcasting\Tests\Fixtures;

use Kinetis\Broadcasting\Attributes\BroadcastChannel;
use Kinetis\Http\CurrentUserInterface;

/**
 * Returns presence data with a valid `user_id` but a value elsewhere
 * that `json_encode()` itself cannot encode (invalid UTF-8) — a real
 * application authorizer bug distinct from a missing/malformed
 * `user_id`.
 */
final class NonEncodablePresenceAuthorizer
{
    /**
     * @return array{user_id: string, bad: string}
     */
    #[BroadcastChannel('nonencodable.{id}')]
    public function authorize(CurrentUserInterface $user, string $id): array
    {
        return ['user_id' => '7', 'bad' => "\xB1\x31"];
    }
}
