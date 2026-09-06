<?php

declare(strict_types=1);

namespace Kinetis\Broadcasting\Tests\Fixtures;

/**
 * The per-request context GuestChannelAuthorizer authorizes from —
 * registered on a RequestScope, never on the AppScope, so each request
 * carries its own.
 */
final readonly class InviteContext
{
    public function __construct(
        public string $inviteId,
    ) {}
}
