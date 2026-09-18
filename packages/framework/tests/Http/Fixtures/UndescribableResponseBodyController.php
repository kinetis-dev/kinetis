<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http\Fixtures;

use Kinetis\Http\Attributes\Get;
use Kinetis\Http\Attributes\Response;

/**
 * A #[Response] whose body names no class at all. Kept as its own
 * fixture to prove document generation refuses it rather than
 * publishing a component under a name nothing backs.
 */
final readonly class UndescribableResponseBodyController
{
    #[Get('/undescribable-error')]
    #[Response(404, description: 'Not found.', body: 'App\\NoSuchClass')]
    public function show(): UserResponse
    {
        return new UserResponse(name: 'Alon', email: 'alon@example.com');
    }
}
