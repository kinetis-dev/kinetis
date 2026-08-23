<?php

declare(strict_types=1);

namespace Kinetis\Console;

use Kinetis\Console\Attributes\Command;
use Kinetis\Http\OpenApi\DocumentationController;
use Psr\SimpleCache\CacheInterface;

/**
 * Drops the cached OpenAPI document.
 *
 * In production the document is cached indefinitely — the route table
 * cannot change without a deployment, so an expiry would only mean
 * serving a document that lies about the API until it lapsed. The
 * consequence is that a deployment which changes routes, DTOs, or
 * constraints has to run this, the same way it runs `kinetis build`.
 *
 * Safe to run when nothing is cached, and in development, where the
 * document is generated per request and nothing is stored.
 */
final readonly class OpenApiClearCommand
{
    public function __construct(private CacheInterface $cache) {}

    #[Command('openapi:clear', description: 'Drops the cached OpenAPI document')]
    public function run(): int
    {
        $existed = $this->cache->has(DocumentationController::CACHE_KEY);

        $this->cache->delete(DocumentationController::CACHE_KEY);

        echo $existed
            ? "Cached OpenAPI document removed.\n"
            : "No cached OpenAPI document to remove.\n";

        return 0;
    }
}
