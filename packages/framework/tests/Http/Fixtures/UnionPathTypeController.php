<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http\Fixtures;

use Kinetis\Http\Attributes\Get;

/**
 * A path parameter declaring a plain union. A path segment is one
 * captured string, so nothing about the request could pick between the
 * two types.
 */
final readonly class UnionPathTypeController
{
    #[Get('/union-path/{id}')]
    public function show(int|string $id): array
    {
        return ['id' => $id];
    }
}
