<?php

declare(strict_types=1);

namespace Kinetis\OpenApi;

/**
 * The static HTML shell for the /docs page. Loads swagger-ui-dist from a
 * CDN rather than vendoring it — this is a docs viewer, not part of the
 * request-handling hot path.
 *
 * Which is also why the page ships its own Content-Security-Policy. An
 * application that sets SECURITY_CSP at all almost certainly sets
 * `script-src 'self'`, and that blocks the CDN this page depends on, so
 * the viewer the framework serves would be broken by the framework's own
 * security guidance. {@see SecurityHeadersMiddleware} never replaces a
 * header a response already carries, so the policy below governs this
 * one page and the application's own governs everything else.
 *
 * The policy is deliberately narrower than a typical application-wide
 * one: no `default-src` fallback beyond `'none'`, scripts limited to a
 * per-response nonce plus the CDN, and `connect-src 'self'` so the page
 * can fetch its own document and nothing else.
 */
final class SwaggerUiPage
{
    private const string CDN = 'https://cdn.jsdelivr.net';

    /**
     * The rendered HTML and the policy that permits it, together —
     * the nonce in the markup has to be the nonce in the header, and
     * returning them separately invites them to drift apart.
     *
     * @return array{html: string, csp: string}
     */
    public static function create(string $specUrl = '/openapi.json'): array
    {
        $nonce = base64_encode(random_bytes(16));

        return [
            'html' => self::render($specUrl, $nonce),
            'csp' => self::contentSecurityPolicy($nonce),
        ];
    }

    public static function render(string $specUrl = '/openapi.json', ?string $nonce = null): string
    {
        $nonceAttribute = $nonce === null ? '' : ' nonce="' . htmlspecialchars($nonce, ENT_QUOTES) . '"';
        $cdn = self::CDN;

        return <<<HTML
        <!doctype html>
        <html>
        <head>
            <title>API Docs</title>
            <meta charset="utf-8">
            <link rel="stylesheet" href="{$cdn}/npm/swagger-ui-dist@5/swagger-ui.css">
        </head>
        <body>
            <div id="swagger-ui"></div>
            <script src="{$cdn}/npm/swagger-ui-dist@5/swagger-ui-bundle.js"></script>
            <script{$nonceAttribute}>
                window.onload = () => window.SwaggerUIBundle({
                    url: '{$specUrl}',
                    dom_id: '#swagger-ui',
                });
            </script>
        </body>
        </html>
        HTML;
    }

    /**
     * `style-src` has to allow inline styles: Swagger UI injects them
     * into the document at runtime, so a nonce cannot cover them. That
     * is the one concession, and it is scoped to this page.
     */
    private static function contentSecurityPolicy(string $nonce): string
    {
        $cdn = self::CDN;

        return implode('; ', [
            "default-src 'none'",
            "script-src 'nonce-{$nonce}' {$cdn}",
            "style-src {$cdn} 'unsafe-inline'",
            "img-src 'self' data:",
            "font-src {$cdn} data:",
            "connect-src 'self'",
            "base-uri 'none'",
            "form-action 'none'",
            "frame-ancestors 'none'",
        ]);
    }
}
