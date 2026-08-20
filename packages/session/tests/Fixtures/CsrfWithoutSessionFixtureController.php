<?php

declare(strict_types=1);

namespace Kinetis\Session\Tests\Fixtures;

use Kinetis\Http\Attributes\Middleware;
use Kinetis\Http\Attributes\Post;
use Kinetis\Session\Middleware\CsrfMiddleware;

/**
 * Deliberately has CsrfMiddleware with no SessionMiddleware ahead of it
 * anywhere — the declaration-order mistake CsrfMiddleware's own
 * isRegistered() check exists to catch cleanly, rather than a request
 * failing deep inside Session's own constructor trying to autowire
 * SessionStoreInterface, which has no binding here at all.
 */
final readonly class CsrfWithoutSessionFixtureController
{
    #[Post('/csrf-without-session')]
    #[Middleware(CsrfMiddleware::class)]
    public function guarded(): array
    {
        return ['changed' => true];
    }
}
