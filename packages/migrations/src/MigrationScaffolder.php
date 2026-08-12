<?php

declare(strict_types=1);

namespace Kinetis\Migrations;

use DateTimeImmutable;

/**
 * Writes a new, timestamped migration file with the up()/down() stubs
 * already filled in — the boilerplate every migration file needs (the
 * anonymous-class shape, the MysqlLink|PostgresLink import) so nobody
 * hand-writes it, and the timestamp prefix so nobody computes it by hand
 * either.
 */
final class MigrationScaffolder
{
    /**
     * @return string the path of the file just written
     */
    public static function create(string $migrationsPath, string $description): string
    {
        if (!is_dir($migrationsPath)) {
            mkdir($migrationsPath, 0755, recursive: true);
        }

        $name = (new DateTimeImmutable())->format('YmdHis') . '_' . self::slugify($description);
        $path = $migrationsPath . '/' . $name . '.php';

        file_put_contents($path, self::stub());

        return $path;
    }

    private static function slugify(string $description): string
    {
        $slug = strtolower(trim((string) preg_replace('/[^A-Za-z0-9]+/', '_', $description), '_'));

        return $slug !== '' ? $slug : 'migration';
    }

    private static function stub(): string
    {
        return <<<'PHP'
            <?php

            declare(strict_types=1);

            use Amp\Mysql\MysqlLink;
            use Amp\Postgres\PostgresLink;
            use Kinetis\Migrations\Migration;

            return new class implements Migration
            {
                public function up(MysqlLink|PostgresLink $db): void
                {
                    //
                }

                public function down(MysqlLink|PostgresLink $db): void
                {
                    //
                }
            };

            PHP;
    }
}
