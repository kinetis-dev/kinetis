<?php

declare(strict_types=1);

namespace App\Tests\Fixtures;

use App\Entities\Ping;
use Kinetis\Orm\EntityManager;
use Kinetis\Orm\Metadata\MetadataRegistry;
use Kinetis\Orm\OrmFactory;

/**
 * A real unit of work over a canned link. The metadata is built from the
 * entity class itself, exactly as kinetis/database-bridge compiles it
 * from the #[Entity] attribute, so a mapping the ORM would refuse fails
 * here too rather than being stubbed past.
 */
final class CannedOrm
{
    public static function manager(CannedMysqlLink $link): EntityManager
    {
        return OrmFactory::create($link, MetadataRegistry::fromClasses([Ping::class]))->open();
    }

    /**
     * A ping_messages row as the MySQL driver returns it: UTC timestamps
     * to the microsecond, which is what a DATETIME(6) column holds.
     *
     * @return array<string, mixed>
     */
    public static function pendingRow(int $id, string $scenario): array
    {
        return [
            'id' => $id,
            'scenario' => $scenario,
            'status' => 'pending',
            'created_at' => '2026-09-17 10:11:12.131415',
            'ponged_at' => null,
        ];
    }
}
