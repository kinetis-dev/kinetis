<?php

declare(strict_types=1);

namespace Kinetis\Views\Tests;

use InvalidArgumentException;
use Kinetis\Views\Exception\ViewNotFoundException;
use Kinetis\Views\ViewDirectory;
use Kinetis\Views\ViewName;
use PHPUnit\Framework\TestCase;

final class ViewDirectoryTest extends TestCase
{
    public function test_resolves_an_existing_file_beneath_the_root(): void
    {
        $root = $this->temporaryDirectory();
        mkdir($root . '/articles');
        file_put_contents($root . '/articles/index.php', 'template');

        try {
            self::assertSame(
                realpath($root . '/articles/index.php'),
                (new ViewDirectory($root))->resolve(new ViewName('articles/index'), 'php'),
            );
        } finally {
            unlink($root . '/articles/index.php');
            rmdir($root . '/articles');
            rmdir($root);
        }
    }

    public function test_rejects_a_missing_root(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ViewDirectory(sys_get_temp_dir() . '/kinetis-views-does-not-exist');
    }

    public function test_reports_a_missing_view_without_revealing_a_filesystem_path(): void
    {
        $root = $this->temporaryDirectory();

        try {
            (new ViewDirectory($root))->resolve(new ViewName('missing'), 'php');
            self::fail('A missing view should throw.');
        } catch (ViewNotFoundException $exception) {
            self::assertSame("View 'missing' was not found.", $exception->getMessage());
            self::assertStringNotContainsString($root, $exception->getMessage());
        } finally {
            rmdir($root);
        }
    }

    public function test_a_symlink_cannot_escape_the_configured_root(): void
    {
        $root = $this->temporaryDirectory();
        $outside = $root . '-outside.php';
        file_put_contents($outside, 'secret');
        symlink($outside, $root . '/escape.php');

        try {
            $this->expectException(ViewNotFoundException::class);
            (new ViewDirectory($root))->resolve(new ViewName('escape'), 'php');
        } finally {
            unlink($root . '/escape.php');
            unlink($outside);
            rmdir($root);
        }
    }

    private function temporaryDirectory(): string
    {
        $path = sys_get_temp_dir() . '/kinetis-views-' . bin2hex(random_bytes(8));
        mkdir($path);

        return $path;
    }
}
