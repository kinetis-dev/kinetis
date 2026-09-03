<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http\Fixtures;

use Kinetis\Http\Attributes\Get;

/**
 * The mixed literal/placeholder route is declared, and therefore
 * reflected, before the fully literal route it would otherwise shadow —
 * proving a fully literal segment beats a mixed one regardless of
 * declaration order, the same way PlaceholderBeforeStaticController
 * proves it for a pure placeholder.
 */
final readonly class MixedAndExactSegmentController
{
    #[Get('/files/report-{id}.pdf')]
    public function template(): array
    {
        return [];
    }

    #[Get('/files/report-2026.pdf')]
    public function exact(): array
    {
        return [];
    }
}
