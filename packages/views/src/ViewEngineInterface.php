<?php

declare(strict_types=1);

namespace Kinetis\Views;

interface ViewEngineInterface
{
    /**
     * @param array<string, mixed> $data
     */
    public function render(ViewName $view, array $data): string;

    /**
     * Rebuilds this engine's complete compiled-template cache.
     *
     * Returns the number of templates compiled. Engines without a
     * filesystem cache, and cached engines in development mode, return zero.
     */
    public function warmCache(): int;

    /**
     * Removes this engine's generated cache files.
     *
     * Returns the number of files or links removed. Engines without a
     * filesystem cache return zero.
     */
    public function clearCache(): int;
}
