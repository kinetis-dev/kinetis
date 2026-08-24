<?php

declare(strict_types=1);

namespace Kinetis\Broadcasting;

/**
 * Always succeeds, delivers nothing — the sensible default nobody has to
 * opt into, the same role `NullSimpleCache`/`NullLogger` play in core. A
 * missed broadcast degrades a live UI update, not a security guarantee,
 * so this stays silent rather than throwing the way an unconfigured rate
 * limiter or revocation store would: {@see PackageBootstrap} binds this
 * whenever no `BROADCAST_DRIVER` is configured, so an application never
 * has to guard every broadcast call behind a "is this configured" check.
 */
final class NullBroadcaster implements BroadcasterInterface
{
    #[\Override]
    public function broadcast(string $channel, string $event, array $payload): void
    {
        // Intentionally empty — see the class docblock.
    }
}
