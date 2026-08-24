<?php

declare(strict_types=1);

namespace Kinetis\Authorization;

use Kinetis\Config\Config;
use Kinetis\Container\AppScope;
use Kinetis\Container\PackageBootstrapInterface;

/**
 * Declared via extra.kinetis. The only thing installing this package
 * changes automatically: a denied Gate::authorize() call becomes a 403
 * for any route, not just ones an application remembers to wrap itself.
 *
 * Gate itself is never bound here — it has no constructor dependencies,
 * so plain autowiring already resolves it wherever a controller
 * constructor-injects it.
 */
final class PackageBootstrap implements PackageBootstrapInterface
{
    #[\Override]
    public function register(AppScope $app, Config $config): void
    {
        $app->middleware(AuthorizationExceptionMiddleware::class);
    }
}
