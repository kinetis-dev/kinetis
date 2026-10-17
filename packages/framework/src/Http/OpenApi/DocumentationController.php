<?php

declare(strict_types=1);

namespace Kinetis\Http\OpenApi;

use Kinetis\Http\Attributes\Get;
use Kinetis\Http\Attributes\Hidden;
use Kinetis\Http\Attributes\Middleware;
use Kinetis\Http\Responses\JsonResponse;
use Kinetis\OpenApi\OpenApiDocumentProvider;
use Kinetis\OpenApi\SwaggerUiPage;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;

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
 * Whether these routes are served is decided before a Kernel exists:
 * {@see \Kinetis\OpenApi\OpenApiAccess::routes()} removes them from the
 * route table of a process that does not serve them, so every request
 * that reaches this class is one it answers.
 *
 * The document itself comes from {@see OpenApiDocumentProvider}, which
 * Kernel builds for its own Router and registers on every request scope.
 */
#[Hidden]
#[Middleware('@openapi')]
final readonly class DocumentationController
{
    public function __construct(
        private OpenApiDocumentProvider $documents,
    ) {}

    #[Get('/openapi.json')]
    public function document(): ResponseInterface
    {
        return JsonResponse::create($this->documents->document());
    }

    #[Get('/openapi')]
    public function ui(): ResponseInterface
    {
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
}
