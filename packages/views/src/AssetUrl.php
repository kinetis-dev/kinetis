<?php

declare(strict_types=1);

namespace Kinetis\Views;

use InvalidArgumentException;

final readonly class AssetUrl
{
    private string $base;

    public function __construct(string $base = '/')
    {
        $this->base = self::normalizeBase($base);
    }

    public function __invoke(string $path): string
    {
        if ($path === '' || $path[0] === '/' || str_contains($path, '\\') || str_contains($path, '?')
            || str_contains($path, '#') || preg_match('/[\x00-\x20\x7F"\'&<>`]/', $path) === 1) {
            throw new InvalidArgumentException('An asset path must be a safe relative URL path without a query or fragment.');
        }

        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new InvalidArgumentException('An asset path cannot contain empty or traversal segments.');
            }
        }

        return $this->base . $path;
    }

    private static function normalizeBase(string $base): string
    {
        if (str_contains($base, '\\') || preg_match('/[\x00-\x20\x7F"\'&<>`]/', $base) === 1) {
            throw new InvalidArgumentException('An asset base cannot contain whitespace or HTML-significant characters.');
        }

        if ($base === '/') {
            return $base;
        }

        if (str_starts_with($base, '/')) {
            if (str_starts_with($base, '//') || str_contains($base, '?') || str_contains($base, '#')) {
                throw new InvalidArgumentException("Asset base '{$base}' is not a valid root-relative URL path.");
            }


            self::assertBasePath($base);

            return rtrim($base, '/') . '/';
        }

        $parts = parse_url($base);

        if ($parts === false || ($parts['scheme'] ?? null) !== 'https' || !isset($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new InvalidArgumentException("Asset base '{$base}' must be root-relative or an HTTPS URL.");
        }

        self::assertBasePath($parts['path'] ?? '/');

        return rtrim($base, '/') . '/';
    }

    private static function assertBasePath(string $path): void
    {
        $trimmed = trim($path, '/');

        if ($trimmed === '') {
            return;
        }

        foreach (explode('/', $trimmed) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new InvalidArgumentException("Asset base '{$path}' contains a traversal segment.");
            }
        }
    }
}
