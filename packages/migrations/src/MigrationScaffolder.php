<?php

declare(strict_types=1);

namespace Kinetis\Migrations;

use Kinetis\Migrations\Exception\MigrationScaffoldException;
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
    private const int MAX_COLLISION_ATTEMPTS = 5;

    /**
     * @return string the path of the file just written
     */
    public static function create(string $migrationsPath, string $description): string
    {
        if (!is_dir($migrationsPath) && !@mkdir($migrationsPath, 0755, recursive: true) && !is_dir($migrationsPath)) {
            // The is_dir() recheck after a failed mkdir() is what tells
            // apart a genuine failure from a benign race against another
            // process creating the same directory concurrently — the
            // same discipline this project's AOT cache directory
            // creation already uses.
            throw MigrationScaffoldException::couldNotCreateDirectory($migrationsPath);
        }

        $timestamp = (new DateTimeImmutable())->format('YmdHis');
        $slug = self::slugify($description);

        for ($attempt = 0; $attempt < self::MAX_COLLISION_ATTEMPTS; $attempt++) {
            // Only the retry attempts add a random suffix — the first
            // attempt keeps the plain, readable "timestamp_slug" name,
            // since a same-second collision on the same description is
            // rare enough not to pay entropy for by default.
            $name = $attempt === 0 ? "{$timestamp}_{$slug}" : "{$timestamp}_{$slug}_" . bin2hex(random_bytes(3));
            $path = "{$migrationsPath}/{$name}.php";

            // Exclusive create ("x"): fails rather than silently
            // overwriting an existing file of the same name, which
            // file_put_contents() would otherwise do unconditionally.
            $handle = @fopen($path, 'x');

            if ($handle === false) {
                if (file_exists($path)) {
                    // A genuine collision — try again with entropy added.
                    continue;
                }

                throw MigrationScaffoldException::couldNotWrite($path);
            }

            // Both effects always run, in this exact order, regardless of
            // which one (or both) fails — fclose() always runs before any
            // unlink(), since unlinking a file PHP still has open is not
            // portable (POSIX permits it; other platforms don't). fclose()
            // can itself fail to flush buffered data to disk even when
            // every fwrite() call above reported success; either failure
            // alone means the file "x" mode already created on disk isn't
            // a valid migration stub, so it's removed rather than left
            // behind to collide against on the next migrate:make run.
            $written = self::writeAll($handle, self::stub());
            $closed = fclose($handle);

            if (!$written || !$closed) {
                @unlink($path);

                throw MigrationScaffoldException::couldNotWrite($path);
            }

            return $path;
        }

        throw MigrationScaffoldException::couldNotWrite("{$migrationsPath}/{$timestamp}_{$slug}.php");
    }

    /**
     * fwrite() may return a positive byte count smaller than the given
     * string's own length under real I/O pressure (PHP's own
     * documentation names a full disk or an interrupting signal) — a
     * single fwrite() call succeeding isn't proof every byte actually
     * landed. Loops until the whole string is written, stopping only
     * once the offset reaches the end or a call makes zero forward
     * progress at all (false, or a literal 0 — a genuine failure, not a
     * partial success worth looping on further).
     *
     * @param resource $handle
     */
    private static function writeAll($handle, string $data): bool
    {
        $length = strlen($data);
        $offset = 0;

        while ($offset < $length) {
            $written = fwrite($handle, substr($data, $offset));

            if ($written === false || $written === 0) {
                return false;
            }

            $offset += $written;
        }

        return true;
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

            use Kinetis\Persistence\Contract\MysqlLink;
            use Kinetis\Persistence\Contract\PostgresLink;
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
