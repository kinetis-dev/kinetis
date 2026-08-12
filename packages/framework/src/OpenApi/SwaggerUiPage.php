<?php

declare(strict_types=1);

namespace Kinetis\OpenApi;

/**
 * The static HTML shell for the /docs page. Loads swagger-ui-dist from a
 * CDN rather than vendoring it — this is a docs viewer, not part of the
 * request-handling hot path.
 */
final class SwaggerUiPage
{
    public static function render(string $specUrl = '/openapi.json'): string
    {
        return <<<HTML
        <!doctype html>
        <html>
        <head>
            <title>API Docs</title>
            <meta charset="utf-8">
            <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/swagger-ui-dist@5/swagger-ui.css">
        </head>
        <body>
            <div id="swagger-ui"></div>
            <script src="https://cdn.jsdelivr.net/npm/swagger-ui-dist@5/swagger-ui-bundle.js"></script>
            <script>
                window.onload = () => window.SwaggerUIBundle({
                    url: '{$specUrl}',
                    dom_id: '#swagger-ui',
                });
            </script>
        </body>
        </html>
        HTML;
    }
}
