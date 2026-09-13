<?php

declare(strict_types=1);

namespace Kinetis\Views\Tests;

use Kinetis\Views\ViewCacheDirectory;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ViewCacheDirectoryTest extends TestCase
{
    public function test_clear_removes_nested_files_and_links_without_touching_the_link_target(): void
    {
        $root = sys_get_temp_dir() . '/kinetis-view-cache-' . bin2hex(random_bytes(8));
        $cache = $root . '/.kinetis-cache/views/twig';
        $outside = $root . '/outside.php';
        mkdir($cache . '/nested', recursive: true);
        file_put_contents($cache . '/cached.php', 'cached');
        file_put_contents($cache . '/nested/cached.php', 'cached');
        file_put_contents($outside, 'outside');
        symlink($outside, $cache . '/linked.php');
        $directory = new ViewCacheDirectory($root, 'twig');

        self::assertSame(3, $directory->clear());
        self::assertDirectoryExists($cache);
        self::assertSame([], array_values(array_diff(scandir($cache) ?: [], ['.', '..'])));
        self::assertFileExists($outside);

        unlink($outside);
        rmdir($cache);
        rmdir(dirname($cache));
        rmdir(dirname($cache, 2));
        rmdir($root);
    }

    public function test_refuses_a_cache_root_that_is_itself_a_symbolic_link(): void
    {
        $root = sys_get_temp_dir() . '/kinetis-view-cache-link-' . bin2hex(random_bytes(8));
        mkdir($root);
        mkdir($root . '/target');
        mkdir($root . '/.kinetis-cache/views', recursive: true);
        symlink($root . '/target', $root . '/.kinetis-cache/views/twig');

        try {
            $this->expectException(RuntimeException::class);
            (new ViewCacheDirectory($root, 'twig'))->clear();
        } finally {
            unlink($root . '/.kinetis-cache/views/twig');
            rmdir($root . '/.kinetis-cache/views');
            rmdir($root . '/.kinetis-cache');
            rmdir($root . '/target');
            rmdir($root);
        }
    }
}
