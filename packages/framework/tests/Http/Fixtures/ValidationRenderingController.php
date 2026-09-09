<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http\Fixtures;

use Kinetis\Http\Attributes\Body;
use Kinetis\Http\Attributes\Middleware;
use Kinetis\Http\Attributes\Post;

/**
 * Two routes over the same DTO: one reaching the terminal renderer with
 * its validation failure, one whose route middleware catches it first.
 */
final readonly class ValidationRenderingController
{
    #[Post('/validation-rendering', status: 201)]
    public function store(#[Body] CreateUserRequest $data): UserResponse
    {
        return new UserResponse(name: $data->name, email: $data->email);
    }

    #[Post('/validation-rendering/caught', status: 201)]
    #[Middleware(FlashingValidationMiddleware::class)]
    public function caught(#[Body] CreateUserRequest $data): UserResponse
    {
        return new UserResponse(name: $data->name, email: $data->email);
    }
}
