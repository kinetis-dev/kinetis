<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http\Fixtures;

use Kinetis\Http\Attributes\Get;

/**
 * One valid route, then one whose constraint cannot compile — the whole
 * controller must contribute zero routes.
 */
final readonly class AtomicConstraintFailureController
{
    #[Get('/atomic-where/valid')]
    public function valid(): array
    {
        return [];
    }

    #[Get('/atomic-where/{id}', where: ['id' => '[z-a]'])]
    public function invalid(string $id): array
    {
        return [];
    }
}
