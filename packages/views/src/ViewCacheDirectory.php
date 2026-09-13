<?php

declare(strict_types=1);

namespace Kinetis\Views;

use FilesystemIterator;
use InvalidArgumentException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

/** @internal Shared by the compiled-template adapters. */
final readonly class ViewCacheDirectory
{
    private string $path;

    public function __construct(string $projectRoot, string $engine)
    {
        $resolved = realpath($projectRoot);

        if ($resolved === false || !is_dir($resolved)) {
            throw new InvalidArgumentException("Project root '{$projectRoot}' is not an existing directory.");
        }

        if (preg_match('/^[a-z][a-z0-9-]*$/D', $engine) !== 1) {
            throw new InvalidArgumentException("Invalid view engine cache name '{$engine}'.");
        }

        $root = $resolved === DIRECTORY_SEPARATOR ? DIRECTORY_SEPARATOR : rtrim($resolved, DIRECTORY_SEPARATOR);
        $separator = $root === DIRECTORY_SEPARATOR ? '' : DIRECTORY_SEPARATOR;
        $this->path = $root . $separator . '.kinetis-cache/views/' . $engine;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function ensureExists(): void
    {
        $this->assertSafeTarget();

        if (is_dir($this->path)) {
            return;
        }

        if (!@mkdir($this->path, 0o777, true) && !is_dir($this->path)) {
            throw new RuntimeException("View cache directory '{$this->path}' could not be created.");
        }
    }

    /** Returns the number of files or links removed. */
    public function clear(): int
    {
        $this->assertSafeTarget();

        if (!file_exists($this->path)) {
            return 0;
        }

        if (!is_dir($this->path)) {
            throw new RuntimeException("View cache path '{$this->path}' is not a directory.");
        }

        $removed = 0;
        $entries = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        /** @var SplFileInfo $entry */
        foreach ($entries as $entry) {
            $entryPath = $entry->getPathname();

            if ($entry->isLink() || !$entry->isDir()) {
                if (!@unlink($entryPath)) {
                    throw new RuntimeException("View cache entry '{$entryPath}' could not be removed.");
                }

                ++$removed;
                continue;
            }

            if (!@rmdir($entryPath)) {
                throw new RuntimeException("View cache directory '{$entryPath}' could not be removed.");
            }
        }

        return $removed;
    }

    private function assertSafeTarget(): void
    {
        clearstatcache(true, $this->path);

        if (is_link($this->path)) {
            throw new RuntimeException("Refusing unsafe view cache directory '{$this->path}'.");
        }
    }
}
