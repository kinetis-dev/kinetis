<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http\Fixtures;

use Kinetis\Http\Attributes\Get;

/**
 * The unconstrained placeholder route is declared, and therefore
 * reflected, *before* the static route it would otherwise shadow —
 * proving Route::compareForMatching() is what decides precedence, not
 * declaration/reflection order. A router that still matched in
 * registration order would resolve "/scoped/self" to byId() here.
 */
final readonly class PlaceholderBeforeStaticController
{
    #[Get('/scoped/{id}')]
    public function byId(): array
    {
        return [];
    }

    #[Get('/scoped/self')]
    public function self(): array
    {
        return [];
    }
}
