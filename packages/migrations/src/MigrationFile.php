<?php

declare(strict_types=1);

namespace Kinetis\Migrations;

/**
 * One discovered migration file. $name is the filename without its `.php`
 * extension (e.g. "20260810143000_create_orders_table") — the timestamp
 * prefix every filename carries is what makes discover()'s plain lexical
 * sort() also a chronological one, and $name doubles as the identifier
 * MigrationRepositoryInterface tracks applied migrations by.
 */
final readonly class MigrationFile
{
    public function __construct(
        public string $name,
        public string $path,
    ) {}

    /**
     * Reflection-free by construction: a migration file's own `return new
     * class implements Migration {...};` is exactly what require() hands
     * back, the same pattern Kinetis\Cache\RoutesFile already uses for
     * bootstrap.php.
     */
    public function load(): Migration
    {
        /** @var Migration */
        return require $this->path;
    }

    /**
     * Sorted ascending by filename, not by filesystem creation order —
     * empty (not an error) when $migrationsPath doesn't exist yet, which
     * is the normal state before the first `migrate make` has ever run.
     *
     * @return list<self>
     */
    public static function discover(string $migrationsPath): array
    {
        if (!is_dir($migrationsPath)) {
            return [];
        }

        $paths = glob($migrationsPath . '/*.php') ?: [];
        sort($paths);

        return array_map(
            static fn (string $path): self => new self(basename($path, '.php'), $path),
            $paths,
        );
    }
}
