<?php

declare(strict_types=1);

namespace Kinetis\Container;

/**
 * The one place "wire `Kinetis\Persistence\TransactionGuard::rollbackDangling()`
 * as a dispose hook, if `kinetis/persistence` happens to be installed" is
 * implemented — every entry point that owns a `RequestScope` for one unit
 * of work (an HTTP request, a CLI command, an MCP message, a queued job)
 * needs the identical safety net, and had been reimplementing it slightly
 * differently at each site rather than sharing one.
 *
 * A plain string, not a `use` import: `kinetis/persistence` is optional,
 * and neither a `use` nor a literal `::class` on an undeclared class
 * triggers autoloading on its own — only `class_exists()` or actual
 * resolution does. A no-op for a unit of work that never opens a
 * transaction, and for one whose process never installed the package at
 * all.
 */
final class TransactionGuardHook
{
    private const string CLASS_NAME = 'Kinetis\Persistence\TransactionGuard';

    public static function registerIfAvailable(RequestScope $scope): void
    {
        if (!class_exists(self::CLASS_NAME)) {
            return;
        }

        $guard = $scope->get(self::CLASS_NAME);
        $scope->onDispose($guard->rollbackDangling(...));
    }
}
