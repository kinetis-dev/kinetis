<?php

declare(strict_types=1);

namespace Kinetis\Views;

use InvalidArgumentException;
use Kinetis\Views\Exception\ViewNotFoundException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final readonly class ViewDirectory
{
    private string $root;

    public function __construct(string $root)
    {
        $resolved = realpath($root);

        if ($resolved === false || !is_dir($resolved)) {
            throw new InvalidArgumentException("View root '{$root}' is not an existing directory.");
        }

        $this->root = $resolved === DIRECTORY_SEPARATOR
            ? DIRECTORY_SEPARATOR
            : rtrim($resolved, DIRECTORY_SEPARATOR);
    }

    public function root(): string
    {
        return $this->root;
    }

    public function resolve(ViewName $view, string $extension): string
    {
        $prefix = $this->root === DIRECTORY_SEPARATOR
            ? DIRECTORY_SEPARATOR
            : $this->root . DIRECTORY_SEPARATOR;
        $path = realpath($prefix . $view->file($extension));

        if ($path === false) {
            throw ViewNotFoundException::named($view->value);
        }

        if (!is_file($path) || !str_starts_with($path, $prefix)) {
            throw ViewNotFoundException::named($view->value);
        }

        return $path;
    }

    /**
     * Finds every renderable template for one adapter in deterministic order.
     *
     * Symbolic links are deliberately omitted. A linked directory could form
     * a cycle, and a linked file would make cache warming compile a source the
     * configured root does not own even though ordinary rendering rejects a
     * target outside that root.
     *
     * @return array<string, string> logical name => canonical absolute path
     */
    public function templates(string $extension): array
    {
        // Validate the extension through the same admitted domain resolve()
        // uses before touching the filesystem.
        new ViewName('template')->file($extension);

        $templates = [];
        $prefixLength = strlen($this->root) + ($this->root === DIRECTORY_SEPARATOR ? 0 : 1);
        $suffix = '.' . $extension;
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->root, RecursiveDirectoryIterator::SKIP_DOTS),
        );

        /** @var SplFileInfo $file */
        foreach ($files as $file) {
            if ($file->isLink() || !$file->isFile()) {
                continue;
            }

            $path = $file->getRealPath();

            if ($path === false || !str_ends_with($path, $suffix)) {
                continue;
            }

            $relative = substr($path, $prefixLength);
            $logical = str_replace(DIRECTORY_SEPARATOR, '/', substr($relative, 0, -strlen($suffix)));
            $view = new ViewName($logical);
            $templates[$view->value] = $this->resolve($view, $extension);
        }

        ksort($templates, SORT_STRING);

        return $templates;
    }
}
