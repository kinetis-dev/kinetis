<?php

declare(strict_types=1);

namespace Kinetis\Broadcasting\Tests\Fixtures;

use Kinetis\Broadcasting\Attributes\BroadcastChannel;

/**
 * Authorizes from its own request-scoped context and declares no
 * CurrentUserInterface — a private channel whose rule is an invite
 * token rather than an application login, so an anonymous request
 * reaches this method instead of a 401.
 */
final readonly class GuestChannelAuthorizer
{
    public function __construct(
        private InviteContext $context,
    ) {}

    #[BroadcastChannel('invites.{inviteId}')]
    public function authorize(string $inviteId): bool
    {
        return $this->context->inviteId === $inviteId;
    }
}
