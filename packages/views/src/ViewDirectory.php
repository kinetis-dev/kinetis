<?php

declare(strict_types=1);

namespace Kinetis\Views;

use InvalidArgumentException;
use Kinetis\Views\Exception\ViewNotFoundException;

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

        if ($path === false || !is_file($path)
            || !str_starts_with($path, $prefix)) {
            throw ViewNotFoundException::named($view->value);
        }

        return $path;
    }
}
