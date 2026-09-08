<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http\Fixtures;

use DateTimeImmutable;
use Kinetis\Http\Attributes\Get;

/**
 * A controller parameter whose default constructs an object. A binding
 * plan captures a default once and reuses it for every later request, so
 * this declaration is rejected while the plan is derived — at
 * Router::register(), the boundary every route passes through. Kept as
 * its own never-registrable fixture to prove that.
 */
final readonly class ObjectDefaultParameterController
{
    #[Get('/reports')]
    public function index(?DateTimeImmutable $since = new DateTimeImmutable()): array
    {
        return ['since' => $since?->format('c')];
    }
}
