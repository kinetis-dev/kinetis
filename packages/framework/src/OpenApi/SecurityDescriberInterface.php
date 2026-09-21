<?php

declare(strict_types=1);

namespace Kinetis\OpenApi;

/**
 * A middleware class that states, as static metadata, which
 * authentication it enforces — the one thing that makes a middleware
 * security-bearing for {@see OpenApiGenerator}. Every other middleware
 * leaves the document unchanged.
 *
 * The generator reads this without constructing the middleware, so the
 * method must be pure: no I/O, no request state, no container, no
 * configuration. It describes the wire mechanism the class implements,
 * which a deployment's credentials and policy do not change. A
 * middleware whose behavior is configured through its constructor still
 * describes the same mechanism here.
 *
 * An interface rather than an attribute, so the thin subclass an
 * application writes to put a middleware in a
 * {@see \Kinetis\Http\Attributes\AsMiddlewareGroup} carries the
 * description without redeclaring it.
 */
interface SecurityDescriberInterface
{
    public static function openApiSecurity(): SecurityDescription;
}
