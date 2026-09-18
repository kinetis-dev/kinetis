<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http\Fixtures;

use Kinetis\Http\Attributes\Get;
use Kinetis\Http\Attributes\Response;

/**
 * Additional statuses documented with and without a body: one JSON
 * error payload, the same DTO again under `application/problem+json`,
 * and one status with nothing but a description.
 */
final readonly class ResponseBodyController
{
    #[Get('/documented-errors')]
    #[Response(404, description: 'User not found.', body: ApiError::class)]
    #[Response(422, description: 'Validation failed.', body: ApiError::class, mediaType: 'application/problem+json')]
    #[Response(503, description: 'Temporarily unavailable.')]
    public function show(): UserResponse
    {
        return new UserResponse(name: 'Alon', email: 'alon@example.com');
    }
}
