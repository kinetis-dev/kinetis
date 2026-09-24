<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http\Fixtures;

use Kinetis\Http\Attributes\Get;

/**
 * Constrained routes beside the static and deeper routes they must not
 * outrank: a constraint narrows admission only, so precedence stays the
 * template's own.
 */
final readonly class ConstrainedRouteController
{
    #[Get('/articles/{slug}', where: ['slug' => '[A-Za-z]+'])]
    public function article(string $slug): array
    {
        return ['slug' => $slug];
    }

    #[Get('/files/readme')]
    public function readme(): array
    {
        return ['file' => 'readme'];
    }

    #[Get('/files/{id}/meta')]
    public function meta(string $id): array
    {
        return ['id' => $id];
    }

    #[Get('/files/{path}', where: ['path' => '.*'])]
    public function file(string $path): array
    {
        return ['path' => $path];
    }

    #[Get('/archives/{year}/{slug}', where: ['year' => '\d{4}'])]
    public function archive(int $year, string $slug): array
    {
        return ['year' => $year, 'slug' => $slug];
    }

    #[Get('/versions/{version}', where: ['version' => '[0-9.]+'])]
    public function version(int $version): array
    {
        return ['version' => $version];
    }
}
