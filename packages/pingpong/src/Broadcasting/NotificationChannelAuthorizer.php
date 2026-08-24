<?php

declare(strict_types=1);

namespace App\Broadcasting;

use Kinetis\Broadcasting\Attributes\BroadcastChannel;
use Kinetis\Http\CurrentUserInterface;

/**
 * Authorizes the `private-notifications` channel — discovered by
 * kinetis/broadcasting's own BroadcastChannelDiscovery, the same
 * attribute-scan every other Kinetis discovery mechanism uses. Any
 * request reaching here already has a CurrentUserInterface (DemoVisitor,
 * bound in bootstrap.php), so this exists purely to demonstrate the real
 * private-channel handshake: the browser calls POST /broadcasting/auth,
 * this method runs, and only a signed response lets pusher-js's
 * subscription through.
 */
final class NotificationChannelAuthorizer
{
    #[BroadcastChannel('notifications')]
    public function authorize(CurrentUserInterface $user): bool
    {
        return true;
    }
}
