<?php

declare(strict_types=1);

namespace Kinetis\Mcp;

use Kinetis\Mcp\Attributes\McpResource;
use Kinetis\Mcp\Exception\DocsResourceException;

/**
 * Exposes Kinetis's own documentation pages as MCP resources — so an
 * agent working in a *consumer's* codebase can pull Kinetis's own docs
 * the same way it pulls the app's own resources, instead of relying on
 * stale training-data knowledge of the framework.
 *
 * Not registered by default — opt in the same way as any other resource
 * class:
 *
 *     $registry->register(KinetisDocsResource::class);
 *
 * Reads the actual `docs/*.md` sources directly from the monorepo root
 * (`dirname(__DIR__, 4)` from `src/Mcp/`), not a separately maintained
 * copy, so there's nothing to keep in sync as pages change. This only
 * resolves correctly when developing Kinetis itself, inside this
 * monorepo — `docs/` covers every package, not just core, so it lives
 * at the monorepo root rather than shipping inside any one installed
 * package. A real `vendor/kinetis/kinetis` install has no `docs/`
 * directory to find at all; this class is not currently usable outside
 * this monorepo.
 *
 * Content is served as-is: real MyST/Sphinx source, not rendered HTML or
 * a stripped-down summary — still highly readable markdown, and "exactly
 * what's in this repo" is more valuable to an agent than a second,
 * separately-maintained artifact that can drift.
 */
final readonly class KinetisDocsResource
{
    private const string MIME_TYPE = 'text/markdown';

    #[McpResource(uri: 'kinetis://docs/index', name: 'index', description: 'Framework overview and feature highlights', mimeType: self::MIME_TYPE)]
    public function index(): string
    {
        return $this->read('index');
    }

    #[McpResource(uri: 'kinetis://docs/getting-started', name: 'getting-started', description: 'Install Kinetis, write your first controller, and run it under FrankenPHP', mimeType: self::MIME_TYPE)]
    public function gettingStarted(): string
    {
        return $this->read('getting-started');
    }

    #[McpResource(uri: 'kinetis://docs/tutorial', name: 'tutorial', description: 'Build a real-time application from scratch: a database, a queue, a scheduled command, and live updates over a WebSocket', mimeType: self::MIME_TYPE)]
    public function tutorial(): string
    {
        return $this->read('tutorial');
    }

    #[McpResource(uri: 'kinetis://docs/core-concepts', name: 'core-concepts', description: 'The runtime-agnostic Kernel, the request lifecycle, and why persistent workers change the rules', mimeType: self::MIME_TYPE)]
    public function coreConcepts(): string
    {
        return $this->read('core-concepts');
    }

    #[McpResource(uri: 'kinetis://docs/container', name: 'container', description: 'AppScope and RequestScope, and why Kinetis bans static properties', mimeType: self::MIME_TYPE)]
    public function container(): string
    {
        return $this->read('container');
    }

    #[McpResource(uri: 'kinetis://docs/config', name: 'config', description: '.env loading and typed Config access', mimeType: self::MIME_TYPE)]
    public function config(): string
    {
        return $this->read('config');
    }

    #[McpResource(uri: 'kinetis://docs/routing-validation', name: 'routing-validation', description: 'Attribute-based routes, typed DTOs, constraint validation, and zero-config OpenAPI', mimeType: self::MIME_TYPE)]
    public function routingValidation(): string
    {
        return $this->read('routing-validation');
    }

    #[McpResource(uri: 'kinetis://docs/middleware', name: 'middleware', description: 'The PSR-15 middleware pipeline: global vs. route, plus the built-in ExceptionHandlerMiddleware/CorsMiddleware/RateLimitMiddleware', mimeType: self::MIME_TYPE)]
    public function middleware(): string
    {
        return $this->read('middleware');
    }

    #[McpResource(uri: 'kinetis://docs/events', name: 'events', description: 'A PSR-14 event dispatcher with attribute-driven listener registration, plus ShouldQueue for deferring a listener to a queue', mimeType: self::MIME_TYPE)]
    public function events(): string
    {
        return $this->read('events');
    }

    #[McpResource(uri: 'kinetis://docs/logging', name: 'logging', description: 'PSR-3 logging: the default NullLogger, and where Kinetis logs on its own', mimeType: self::MIME_TYPE)]
    public function logging(): string
    {
        return $this->read('logging');
    }

    #[McpResource(uri: 'kinetis://docs/runtime-adapters', name: 'runtime-adapters', description: 'FrankenPHP, PHP-FPM, and AWS Lambda adapters, and how RuntimeDetector picks one', mimeType: self::MIME_TYPE)]
    public function runtimeAdapters(): string
    {
        return $this->read('runtime-adapters');
    }

    #[McpResource(uri: 'kinetis://docs/concurrency', name: 'concurrency', description: 'Fiber-based concurrency over Revolt: concurrently(), Async\Socket, and Async\Timer', mimeType: self::MIME_TYPE)]
    public function concurrency(): string
    {
        return $this->read('concurrency');
    }

    #[McpResource(uri: 'kinetis://docs/persistence', name: 'persistence', description: 'MySQL/Postgres/Redis clients over AMPHP, and TransactionGuard\'s commit/rollback protocol', mimeType: self::MIME_TYPE)]
    public function persistence(): string
    {
        return $this->read('persistence');
    }

    #[McpResource(uri: 'kinetis://docs/migrations', name: 'migrations', description: 'kinetis/migrations: a thin database migration runner — raw SQL up()/down(), no schema-diffing', mimeType: self::MIME_TYPE)]
    public function migrations(): string
    {
        return $this->read('migrations');
    }

    #[McpResource(uri: 'kinetis://docs/query-builder', name: 'query-builder', description: 'kinetis/query-builder: a thin, parameterized SQL query builder — not an ORM', mimeType: self::MIME_TYPE)]
    public function queryBuilder(): string
    {
        return $this->read('query-builder');
    }

    #[McpResource(uri: 'kinetis://docs/queue', name: 'queue', description: 'kinetis/queue: a backend-agnostic background job queue — Redis and SQL backends included', mimeType: self::MIME_TYPE)]
    public function queue(): string
    {
        return $this->read('queue');
    }

    #[McpResource(uri: 'kinetis://docs/queue-sqs', name: 'queue-sqs', description: 'kinetis/queue-sqs: an Amazon SQS backend for kinetis/queue\'s QueueInterface', mimeType: self::MIME_TYPE)]
    public function queueSqs(): string
    {
        return $this->read('queue-sqs');
    }

    #[McpResource(uri: 'kinetis://docs/queue-rabbitmq', name: 'queue-rabbitmq', description: 'kinetis/queue-rabbitmq: a RabbitMQ backend for kinetis/queue\'s QueueInterface', mimeType: self::MIME_TYPE)]
    public function queueRabbitMq(): string
    {
        return $this->read('queue-rabbitmq');
    }

    #[McpResource(uri: 'kinetis://docs/storage', name: 'storage', description: 'kinetis/storage: file storage on League\Flysystem — a genuinely non-blocking, Amp\File-backed local adapter', mimeType: self::MIME_TYPE)]
    public function storage(): string
    {
        return $this->read('storage');
    }

    #[McpResource(uri: 'kinetis://docs/storage-s3', name: 'storage-s3', description: 'kinetis/storage-s3: Amazon S3 (and S3-compatible) storage for kinetis/storage, non-blocking via kinetis/revolt-http-client', mimeType: self::MIME_TYPE)]
    public function storageS3(): string
    {
        return $this->read('storage-s3');
    }

    #[McpResource(uri: 'kinetis://docs/mailer', name: 'mailer', description: 'kinetis/mailer: mail sending via Symfony\Component\Mailer, non-blocking via kinetis/revolt-http-client for API-based transports', mimeType: self::MIME_TYPE)]
    public function mailer(): string
    {
        return $this->read('mailer');
    }

    #[McpResource(uri: 'kinetis://docs/search-opensearch', name: 'search-opensearch', description: 'kinetis/search-opensearch: OpenSearch client construction, non-blocking via kinetis/revolt-http-client', mimeType: self::MIME_TYPE)]
    public function searchOpenSearch(): string
    {
        return $this->read('search-opensearch');
    }

    #[McpResource(uri: 'kinetis://docs/auth', name: 'auth', description: 'kinetis/auth: opaque Bearer-token authentication middleware', mimeType: self::MIME_TYPE)]
    public function auth(): string
    {
        return $this->read('auth');
    }

    #[McpResource(uri: 'kinetis://docs/auth-jwt', name: 'auth-jwt', description: 'kinetis/auth-jwt: stateless JWT authentication (HS256/RS256), with optional per-token revocation', mimeType: self::MIME_TYPE)]
    public function authJwt(): string
    {
        return $this->read('auth-jwt');
    }

    #[McpResource(uri: 'kinetis://docs/mcp', name: 'mcp', description: 'The native Model Context Protocol server: tools, resources, transports, and protocol-era handling', mimeType: self::MIME_TYPE)]
    public function mcp(): string
    {
        return $this->read('mcp');
    }

    #[McpResource(uri: 'kinetis://docs/caching', name: 'caching', description: 'Production-only AOT caching of routes, MCP registrations, validation plans, and OpenAPI, plus APP_ENV', mimeType: self::MIME_TYPE)]
    public function caching(): string
    {
        return $this->read('caching');
    }

    #[McpResource(uri: 'kinetis://docs/cli', name: 'cli', description: 'The kinetis CLI: mcp:serve and build', mimeType: self::MIME_TYPE)]
    public function cli(): string
    {
        return $this->read('cli');
    }

    #[McpResource(uri: 'kinetis://docs/testing', name: 'testing', description: 'TestClient, for exercising a Kernel end-to-end in a consumer\'s own test suite', mimeType: self::MIME_TYPE)]
    public function testing(): string
    {
        return $this->read('testing');
    }

    #[McpResource(uri: 'kinetis://docs/appendix', name: 'appendix', description: 'A dense, file-by-file reference across every namespace in core', mimeType: self::MIME_TYPE)]
    public function appendix(): string
    {
        return $this->read('appendix');
    }

    #[McpResource(uri: 'kinetis://docs/appendix-packages', name: 'appendix-packages', description: 'A dense, file-by-file reference across every satellite package', mimeType: self::MIME_TYPE)]
    public function appendixPackages(): string
    {
        return $this->read('appendix-packages');
    }

    #[McpResource(uri: 'kinetis://docs/appendix-ci', name: 'appendix-ci', description: 'What CI actually tests, how, and what is deliberately not covered', mimeType: self::MIME_TYPE)]
    public function appendixCi(): string
    {
        return $this->read('appendix-ci');
    }

    #[McpResource(uri: 'kinetis://docs/revolt-http-client', name: 'revolt-http-client', description: 'kinetis/revolt-http-client: a Revolt-native Symfony HttpClientInterface — usable standalone, no Kinetis required', mimeType: self::MIME_TYPE)]
    public function revoltHttpClient(): string
    {
        return $this->read('revolt-http-client');
    }

    #[McpResource(uri: 'kinetis://docs/aws-sigv4', name: 'aws-sigv4', description: 'kinetis/aws-sigv4: a PSR-18 client decorator signing requests with AWS Signature Version 4, non-blocking via kinetis/revolt-http-client — usable standalone', mimeType: self::MIME_TYPE)]
    public function awsSigV4(): string
    {
        return $this->read('aws-sigv4');
    }

    private function read(string $slug): string
    {
        $path = dirname(__DIR__, 4) . "/docs/{$slug}.md";
        $content = file_get_contents($path);

        if ($content === false) {
            throw DocsResourceException::missingPage($slug, $path);
        }

        return $content;
    }
}
