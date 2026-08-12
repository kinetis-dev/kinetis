<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http\Fixtures;

use Kinetis\Http\Attributes\Get;
use Kinetis\Http\Attributes\Query;
use Kinetis\Validation\Constraints\GreaterThan;
use Kinetis\Validation\Constraints\In;
use Kinetis\Validation\Constraints\Regex;

final readonly class ConstrainedParametersController
{
    #[Get('/probe')]
    public function probe(
        #[Query, GreaterThan(0)] int $page,
        #[Query, In(['asc', 'desc'])] string $sort,
    ): array {
        return ['page' => $page, 'sort' => $sort];
    }

    #[Get('/items/{code}')]
    public function item(#[Regex('#^[A-Z]{3}$#')] string $code): array
    {
        return ['code' => $code];
    }

    #[Get('/products/{id:\d+}')]
    public function product(int $id): array
    {
        return ['id' => $id];
    }
}
