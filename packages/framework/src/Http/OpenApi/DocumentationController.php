<?php

declare(strict_types=1);

namespace Kinetis\Http\OpenApi;

use Kinetis\Http\Attributes\Get;
use Kinetis\Http\Attributes\Hidden;
use Kinetis\Http\Attributes\Middleware;
use Kinetis\Http\Responses\ErrorResponse;
use Kinetis\OpenApi\OpenApiAccess;
use Kinetis\OpenApi\OpenApiDocumentProvider;
use Kinetis\OpenApi\SwaggerUiPage;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

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
 * The document itself comes from {@see OpenApiDocumentProvider}, which
 * Kernel builds for its own Router and registers on every request scope.
 */
#[Hidden]
#[Middleware('@openapi')]
final readonly class DocumentationController
{
    public function __construct(
        private OpenApiAccess $access,
        private OpenApiDocumentProvider $documents,
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
            body: json_encode($this->documents->document(), JSON_THROW_ON_ERROR),
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
}
