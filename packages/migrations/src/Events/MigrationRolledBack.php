<?php

declare(strict_types=1);

namespace Kinetis\Migrations\Events;

/**
 * Dispatched by Console\RollbackCommand when MigrationRunner::rollback()
 * actually rolls one back — never fired when there was nothing to roll
 * back.
 */
final readonly class MigrationRolledBack
{
    public function __construct(
        public string $name,
    ) {}
}
