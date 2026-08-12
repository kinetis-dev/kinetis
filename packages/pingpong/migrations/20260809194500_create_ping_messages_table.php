<?php

declare(strict_types=1);

use Amp\Mysql\MysqlLink;
use Amp\Postgres\PostgresLink;
use Kinetis\Migrations\Migration;

return new class implements Migration
{
    public function up(MysqlLink|PostgresLink $db): void
    {
        $db->execute(<<<'SQL'
            CREATE TABLE ping_messages (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                scenario VARCHAR(20) NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'pending',
                created_at DATETIME NOT NULL,
                ponged_at DATETIME NULL
            )
            SQL);
    }

    public function down(MysqlLink|PostgresLink $db): void
    {
        $db->execute('DROP TABLE ping_messages');
    }
};
