<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http\Fixtures;

use Kinetis\Http\Attributes\Get;
use Kinetis\Tests\Validation\Fixtures\SortDirection;

/**
 * The enum-case half: a case is a process-wide singleton, so a binding
 * plan that captures one hands every request the same value PHP would
 * have evaluated for it, and the compiled artifact writes it as a
 * literal that reloads into that same case.
 */
final readonly class EnumDefaultParameterController
{
    #[Get('/sorted')]
    public function index(SortDirection $direction = SortDirection::Descending): array
    {
        return ['direction' => $direction->value];
    }
}
