<?php

declare(strict_types=1);

/*
 * Deliberately not PSR-4 autoloadable: these classes live in the global
 * namespace, which is the one case a namespace-to-directory mapping
 * cannot express, and the case that used to lose the first character of
 * its schema name. Loaded by an explicit require_once from
 * OpenApiGeneratorTest, the same as tests/Http/Fixtures/gc_collect_cycles_spy.php.
 */

use Kinetis\Http\Attributes\Get;

final class GlobalNamespaceDto
{
    public function __construct(public string $name) {}
}

final class GlobalNamespaceController
{
    #[Get('/global-dto')]
    public function show(): GlobalNamespaceDto
    {
        return new GlobalNamespaceDto('probe');
    }
}
