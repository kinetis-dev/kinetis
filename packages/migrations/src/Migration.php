<?php

declare(strict_types=1);

namespace Kinetis\Migrations;

use Amp\Mysql\MysqlLink;
use Amp\Postgres\PostgresLink;

/**
 * Implemented by the anonymous class a migration file returns. Raw SQL
 * only — no fluent DDL builder — issued via $db->execute()/$db->query(),
 * the same Amp\Sql\SqlLink methods Kinetis\QueryBuilder\Query itself calls
 * underneath its fluent API. A multi-statement migration is multiple
 * execute() calls, not one string with semicolons, since AMPHP's drivers
 * don't reliably support multi-statement execution in a single call.
 *
 * $db is not wrapped in a transaction by MigrationRunner — see that
 * class's docblock for why forcing one would be real atomicity on
 * Postgres and a false sense of it on MySQL.
 */
interface Migration
{
    public function up(MysqlLink|PostgresLink $db): void;

    public function down(MysqlLink|PostgresLink $db): void;
}
