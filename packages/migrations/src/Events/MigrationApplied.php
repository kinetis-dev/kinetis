<?php

declare(strict_types=1);

namespace Kinetis\Migrations\Events;

/**
 * Dispatched by Console\MigrateCommand once per migration MigrationRunner::migrate()
 * actually ran, in the order they ran — nothing else observes a migration
 * running in production, since `kinetis migrate` is typically invoked
 * outside any request context (a deploy step, an init container).
 */
final readonly class MigrationApplied
{
    public function __construct(
        public string $name,
    ) {}
}
