<?php

declare(strict_types=1);

namespace Kinetis\Http\OpenApi;

use Kinetis\Http\Attributes\Get;
use Kinetis\Http\Attributes\Hidden;
use Kinetis\Http\Attributes\Middleware;
use Kinetis\Http\Responses\ErrorResponse;
use Kinetis\Http\Routing\Router;
use Kinetis\OpenApi\OpenApiAccess;
use Kinetis\OpenApi\OpenApiGenerator;
use Kinetis\OpenApi\SwaggerUiPage;
use Kinetis\Runtime\AppEnvironment;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;
use Throwable;

/**
 * Serves the generated OpenAPI document and the Swagger UI page that
 * renders it. An ordinary discovered controller: it lives under
 * Kinetis\Http, which RouteDiscovery already scans, so both routes
 * register themselves and appear in `kinetis routes:list` alongside the
 * application's own.
 *
 * `#[Hidden]` keeps the pair out of the document they produce.
 * `#[Middleware('@openapi')]` is what makes #[AsOpenApiMiddleware] apply
 * here and nowhere else — GlobalMiddlewareDiscovery publishes those
 * classes as the built-in `openapi` group.
 *
 * The document is generated per request in development and cached
 * indefinitely in production, where the route table cannot change
 * without a deployment. `kinetis openapi:clear` drops the entry, and a
 * deployment that changes routes or DTOs has to run it.
 */
#[Hidden]
#[Middleware('@openapi')]
final readonly class DocumentationController
{
    /**
     * The key the cached document lives under, shared with
     * {@see \Kinetis\Console\OpenApiClearCommand}. Dots only: PSR-16
     * reserves `{}()/\@:` in a key.
     */
    public const string CACHE_KEY = 'kinetis.openapi.document';

    public function __construct(
        private OpenApiAccess $access,
        private Router $router,
        private AppEnvironment $environment,
        private CacheInterface $cache,
        private LoggerInterface $logger,
    ) {}

    #[Get('/openapi.json')]
    public function document(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->access->isEnabled()) {
            return $this->closed($request);
        }

        return new Response(
            status: 200,
            headers: ['Content-Type' => 'application/json'],
            body: json_encode($this->generate(), JSON_THROW_ON_ERROR),
        );
    }

    #[Get('/openapi')]
    public function ui(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->access->isEnabled()) {
            return $this->closed($request);
        }

        $page = SwaggerUiPage::create();

        // The page's own policy, not the application's: an application-wide
        // `script-src 'self'` would block the CDN this viewer loads from,
        // and SecurityHeadersMiddleware leaves a header a response already
        // carries alone. See SwaggerUiPage.
        return new Response(
            status: 200,
            headers: ['Content-Type' => 'text/html', 'Content-Security-Policy' => $page['csp']],
            body: $page['html'],
        );
    }

    /**
     * Indistinguishable from a path that was never registered: a closed
     * endpoint that answered 403 would confirm it exists elsewhere.
     */
    private function closed(ServerRequestInterface $request): ResponseInterface
    {
        // Byte-identical to Kernel's own message for an unmatched path,
        // so a closed endpoint cannot be told from an absent one.
        return ErrorResponse::create(404, sprintf(
            'No route matches path "%s".',
            $request->getUri()->getPath(),
        ));
    }

    /**
     * The cache is an optimisation with a guaranteed fallback: this
     * document can always be regenerated. That is why a cache failure
     * degrades to generating rather than failing the request, unlike
     * RateLimitMiddleware, which cannot do its job at all without one.
     * A Redis that is briefly unreachable should not take the API's own
     * documentation down with it.
     *
     * @return array<string, mixed>|null
     */
    private function cached(): ?array
    {
        try {
            $cached = $this->cache->get(self::CACHE_KEY);
        } catch (Throwable $e) {
            $this->logger->warning('Could not read the cached OpenAPI document; generating it instead.', ['exception' => $e]);

            return null;
        }

        /** @var array<string, mixed>|null */
        return is_array($cached) ? $cached : null;
    }

    /**
     * @param array<string, mixed> $document
     */
    private function store(array $document): void
    {
        try {
            // No TTL: the route table cannot change without a deployment,
            // and a deployment runs `kinetis openapi:clear`. A time-based
            // expiry would only mean serving a document that lies about
            // the API for however long it is set to.
            $this->cache->set(self::CACHE_KEY, $document);
        } catch (Throwable $e) {
            $this->logger->warning('Could not cache the OpenAPI document; it will be regenerated per request.', ['exception' => $e]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function generate(): array
    {
        // Development always reflects: a route or DTO changed a moment
        // ago is the normal case there, and a cached document would
        // describe the code as it was.
        if (!$this->environment->isProduction()) {
            return new OpenApiGenerator($this->router)->generate();
        }

        $cached = $this->cached();

        if ($cached !== null) {
            return $cached;
        }

        $document = new OpenApiGenerator($this->router)->generate();
        $this->store($document);

        return $document;
    }
}
