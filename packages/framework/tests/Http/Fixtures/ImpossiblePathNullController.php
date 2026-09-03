<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http\Fixtures;

use Kinetis\Http\Attributes\Get;

/**
 * The path-sourced sibling of ImpossibleQueryNullController — a route
 * placeholder is filled the same way a #[Query] parameter is, always a
 * raw non-empty string, so a standalone-`null`-typed path parameter is
 * equally impossible to ever satisfy. Path parameters have no way to be
 * "optional" (a placeholder is always present when the route matches at
 * all), so there is no analogous defaulted/safe case here at all —
 * every standalone-`null`-typed path parameter is unconditionally
 * rejected, not just a defaultless one.
 */
final readonly class ImpossiblePathNullController
{
    #[Get('/impossible-path-null/{marker}')]
    public function show(null $marker): array
    {
        return ['marker' => $marker];
    }
}
