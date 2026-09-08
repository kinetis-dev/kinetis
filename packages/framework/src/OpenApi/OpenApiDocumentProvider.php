<?php

declare(strict_types=1);

namespace Kinetis\OpenApi;

use Kinetis\Http\Routing\Router;
use Kinetis\Runtime\AppEnvironment;

/**
 * Supplies the OpenAPI document {@see \Kinetis\Http\OpenApi\DocumentationController}
 * serves, bound to one Router.
 *
 * `Kernel` constructs one of these for its own Router and hands it to
 * every request scope, so the document a process serves can only ever
 * describe the route table that process is dispatching against. That is
 * the whole invalidation story: a deployment is a new process with a new
 * Kernel, a new Router and a new provider, so there is nothing to clear
 * and no shared state a previous application could leave behind.
 *
 * Production memoizes for this provider's lifetime — the route table
 * cannot change under it — and development generates every time, so a
 * route or DTO edited a moment ago is described on the next request that
 * the reload behavior of the environment allows.
 *
 * The memo is per-instance rather than static: a Kernel belongs to one
 * worker thread, so this is per-thread state that dies with it.
 */
final class OpenApiDocumentProvider
{
    /** @var array<string, mixed>|null */
    private ?array $memoized = null;

    public function __construct(
        private readonly Router $router,
        private readonly AppEnvironment $environment,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function document(): array
    {
        if (!$this->environment->isProduction()) {
            return new OpenApiGenerator($this->router)->generate();
        }

        return $this->memoized ??= new OpenApiGenerator($this->router)->generate();
    }
}
