<?php

declare(strict_types=1);

namespace Kinetis\Migrations\Tests\Fixtures;

/**
 * One database the migrate:* commands reach through FakeDatabaseLink:
 * its kinetis_migrations ledger, and every other statement a migration
 * executed on it. Outlives the links opened on it, as a real database
 * outlives its sessions.
 */
final class FakeDatabase
{
    /** @var array<string, string> migration => checksum, in application order */
    public array $ledger = [];

    /** @var list<string> */
    public array $executed = [];
}
