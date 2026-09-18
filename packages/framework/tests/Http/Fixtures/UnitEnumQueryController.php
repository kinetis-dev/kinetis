<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http\Fixtures;

use Kinetis\Http\Attributes\Get;
use Kinetis\Http\Attributes\Query;
use Kinetis\Tests\Validation\Fixtures\Weekday;

/**
 * A #[Query] parameter typed as a unit enum. Its cases have no backing
 * value, so no query string can name one. Kept as its own
 * never-registrable fixture to prove Dispatcher::derivePlan() rejects
 * the declaration.
 */
final readonly class UnitEnumQueryController
{
    #[Get('/unit-enum-query')]
    public function show(#[Query] Weekday $day): array
    {
        return ['day' => $day->name];
    }
}
