<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http\Fixtures;

use Kinetis\Http\Attributes\Get;

/**
 * Registers one perfectly valid route, then one with an unrooted path —
 * proving register()'s all-or-nothing contract: the whole controller
 * must contribute zero routes when any one of its methods fails, not
 * just the ones reflected before the failure.
 */
final readonly class AtomicRegistrationFailureController
{
    #[Get('/atomic-fail/valid')]
    public function valid(): array
    {
        return [];
    }

    #[Get('not-rooted')]
    public function invalid(): array
    {
        return [];
    }
}
