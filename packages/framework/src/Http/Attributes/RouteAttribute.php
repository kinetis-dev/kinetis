<?php

declare(strict_types=1);

namespace Kinetis\Http\Attributes;

/**
 * Implemented by every HTTP-verb attribute (Get, Post, ...). Router::register()
 * finds route attributes via getAttributes(RouteAttribute::class,
 * ReflectionAttribute::IS_INSTANCEOF) rather than checking each verb class by
 * name, so adding a new verb never requires touching the Router.
 */
interface RouteAttribute
{
    public function httpMethod(): string;

    public function path(): string;

    public function status(): int;
}
