<?php

declare(strict_types=1);

namespace App\Broadcasting;

use Kinetis\Http\CurrentUserInterface;

/**
 * This app has no real login — every visitor is treated as the same
 * fixed "visitor" identity, bound in bootstrap.php as an AppScope
 * singleton (delegated to by every RequestScope, per its own explicit-
 * registration rule) rather than resolved per request by a real auth
 * middleware. That is enough to demonstrate kinetis/broadcasting's
 * private-channel authorization flow — see NotificationChannelAuthorizer
 * — without building session/login infrastructure this demo doesn't
 * otherwise need.
 */
final readonly class DemoVisitor implements CurrentUserInterface
{
    #[\Override]
    public function id(): string
    {
        return 'visitor';
    }
}
