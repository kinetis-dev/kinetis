<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http\Fixtures;

use Kinetis\Http\Attributes\Get;
use Kinetis\Http\Attributes\Query;
use Kinetis\Tests\Validation\Fixtures\Priority;
use Kinetis\Tests\Validation\Fixtures\SortDirection;

/**
 * Backed enums on every request-reading parameter source: a
 * string-backed and an int-backed #[Query] value, a nullable one, a
 * defaulted one, and a route placeholder. Each returns the bound case's
 * own backing value, so a response says which case was resolved rather
 * than only that binding succeeded.
 */
final readonly class EnumParameterController
{
    #[Get('/enum-query')]
    public function show(#[Query] SortDirection $direction, #[Query] Priority $priority): array
    {
        return ['direction' => $direction->value, 'priority' => $priority->value];
    }

    #[Get('/enum-query-nullable')]
    public function nullable(#[Query] ?SortDirection $direction): array
    {
        return ['direction' => $direction?->value];
    }

    #[Get('/enum-query-default')]
    public function defaulted(#[Query] SortDirection $direction = SortDirection::Ascending): array
    {
        return ['direction' => $direction->value];
    }

    #[Get('/enum-path/{direction}')]
    public function path(SortDirection $direction): array
    {
        return ['direction' => $direction->value];
    }
}
