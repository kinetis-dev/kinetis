<?php

declare(strict_types=1);

namespace Kinetis\Http\Routing;

final class RouteMatch
{
    public function __construct(
        public readonly Route $route,
        /** @var array<string,string> */
        public readonly array $pathParams,
    ) {}
}
