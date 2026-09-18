<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http\Fixtures;

use Kinetis\Http\Attributes\Get;
use Kinetis\Http\Attributes\Query;

/**
 * A #[Query] parameter typed as an ordinary class. A query value is one
 * piece of text and a DTO is a document, so nothing a request sends
 * could build one here. Kept as its own never-registrable fixture to
 * prove Dispatcher::derivePlan() rejects the declaration rather than
 * broadening class binding to a query string.
 */
final readonly class ClassTypedQueryController
{
    #[Get('/class-typed-query')]
    public function show(#[Query] CreateUserRequest $filter): array
    {
        return ['name' => $filter->name];
    }
}
