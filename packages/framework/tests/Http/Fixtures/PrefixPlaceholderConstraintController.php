<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http\Fixtures;

use Kinetis\Http\Attributes\Get;
use Kinetis\Http\Attributes\RoutePrefix;

/**
 * A route constraining a placeholder its #[RoutePrefix] contributes:
 * constraint keys are validated against the composed template.
 */
#[RoutePrefix('/tenants/{tenant}')]
final readonly class PrefixPlaceholderConstraintController
{
    #[Get('/reports', where: ['tenant' => '[a-z]+'])]
    public function reports(string $tenant): array
    {
        return ['tenant' => $tenant];
    }
}
