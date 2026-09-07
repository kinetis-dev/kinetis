<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http\Fixtures;

use Kinetis\Http\Attributes\Body;
use Kinetis\Http\Attributes\Post;

/**
 * A controller whose construction is directly observable, so a test can
 * assert that a request refused before binding completes never reaches
 * the controller — the constructor stands in for whatever application
 * side effect a real one, or a registered factory, would carry.
 */
final class ConstructionCountingController
{
    public static int $constructions = 0;

    public function __construct()
    {
        ++self::$constructions;
    }

    #[Post('/counted-users', status: 201)]
    public function store(#[Body] CreateUserRequest $data): UserResponse
    {
        return new UserResponse(name: $data->name, email: $data->email);
    }
}
