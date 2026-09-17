<?php

declare(strict_types=1);

use Kinetis\Persistence\Contract\MysqlLink;
use Kinetis\Persistence\Contract\PostgresLink;
use Kinetis\Migrations\Migration;

/**
 * App\Entities\Ping writes UTC timestamps to the microsecond, and a
 * plain DATETIME cannot retain a fractional second — it discards the
 * fraction on the way in, so a row stops matching the entity that wrote
 * it. The create migration stays as it was applied; this widens the
 * columns it declared, for a database that already ran it and for a
 * fresh install running the sequence in order.
 */
return new class implements Migration
{
    public function up(MysqlLink|PostgresLink $db): void
    {
        $db->execute(<<<'SQL'
            ALTER TABLE ping_messages
                MODIFY created_at DATETIME(6) NOT NULL,
                MODIFY ponged_at DATETIME(6) NULL
            SQL);
    }

    public function down(MysqlLink|PostgresLink $db): void
    {
        $db->execute(<<<'SQL'
            ALTER TABLE ping_messages
                MODIFY created_at DATETIME NOT NULL,
                MODIFY ponged_at DATETIME NULL
            SQL);
    }
};
