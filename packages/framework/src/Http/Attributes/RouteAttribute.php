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

    /**
     * Admission constraints keyed by placeholder name: each value is a
     * delimiterless PCRE2 fragment the captured text must match whole.
     * A request whose placeholder text fails its fragment is a route
     * miss, never a different handler — see Kinetis\Http\Routing\Route.
     *
     * @return array<string,string>
     */
    public function where(): array;
}
