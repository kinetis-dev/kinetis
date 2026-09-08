<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http\Fixtures;

use Kinetis\Http\Attributes\Get;

/**
 * The path-sourced sibling of UnsupportedQueryTypeController: a route
 * placeholder is captured as a raw string too, so the same closed
 * builtin set applies to it.
 */
final readonly class UnsupportedPathTypeController
{
    #[Get('/unsupported-path-type/{marker}')]
    public function show(null $marker): array
    {
        return ['marker' => $marker];
    }
}
