<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http\Fixtures;

use Kinetis\Http\Attributes\Get;
use Kinetis\Http\Attributes\Post;
use Kinetis\Http\Attributes\Response;

/**
 * Routes carrying a #[Response] that repeats their own status, alongside
 * ones that genuinely add a status — the first is ignored, the second
 * documented. One route leaves the status at its 200 default, the other
 * sets it explicitly, so the rule is exercised against the route's real
 * status rather than a hardcoded 200.
 */
final readonly class SameStatusResponseController
{
    #[Get('/echo')]
    #[Response(200, description: 'Repeats the route status.')]
    #[Response(404, description: 'Not found.')]
    public function echoUser(): UserResponse
    {
        return new UserResponse(name: 'Alon', email: 'alon@noy.cc');
    }

    #[Post('/echo-created', status: 201)]
    #[Response(201, description: 'Repeats the route status.')]
    #[Response(422, description: 'Validation failed.')]
    public function createUser(): UserResponse
    {
        return new UserResponse(name: 'Alon', email: 'alon@noy.cc');
    }
}
