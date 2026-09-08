<?php

declare(strict_types=1);

namespace Kinetis\McpDocs;

/**
 * Every page of the Kinetis documentation site, as the fixed set of MCP
 * resources this server exposes. The catalogue is written out here
 * rather than derived at runtime: the pages live in the
 * kinetis-dev/kinetis monorepo, which this package does not depend on
 * and does not ship with, so there is nothing on disk to enumerate.
 *
 * `tests/DocsCatalogueTest.php` pairs this list against the monorepo's
 * own `docs/*.md` directory, so a page added without an entry here (or
 * an entry naming a page that no longer exists) fails the suite.
 */
final class DocsCatalogue
{
    public const string URI_PREFIX = 'kinetis://docs/';
    public const string SOURCE_BASE_URL = 'https://raw.githubusercontent.com/kinetis-dev/kinetis/main/docs/';
    public const string MIME_TYPE = 'text/markdown';

    // Never instantiated — every method here is static.
    private function __construct() {}

    /**
     * @return list<DocsPage>
     */
    public static function pages(): array
    {
        return [
            new DocsPage('index', 'Kinetis', 'Framework overview: what Kinetis is, what ships in core, and what each satellite package adds'),
            new DocsPage('tutorial', 'Tutorial', 'Build a real-time application from scratch: a database, a queue, a scheduled command, and live updates over a WebSocket'),
            new DocsPage('core-concepts', 'Core Concepts', 'The runtime-agnostic Kernel, the request lifecycle, and why persistent workers change the rules'),
            new DocsPage('container', 'Container', 'AppScope and RequestScope, and why Kinetis bans static properties'),
            new DocsPage('config', 'Configuration', '.env loading and typed Config access'),
            new DocsPage('routing-validation', 'Routing & Validation', 'Attribute-based routes, typed DTOs, constraint validation, and zero-config OpenAPI'),
            new DocsPage('middleware', 'Middleware', 'The PSR-15 middleware pipeline: global vs. route, plus the built-in ExceptionHandlerMiddleware/CorsMiddleware/RateLimitMiddleware'),
            new DocsPage('events', 'Events', 'A PSR-14 event dispatcher with attribute-driven listener registration, plus ShouldQueue for deferring a listener to a queue'),
            new DocsPage('cli', 'CLI', 'The kinetis CLI: built-in commands, application commands via #[Command], commands from installed packages, and restricting discovery'),
            new DocsPage('testing', 'Testing', 'TestClient, for exercising a Kernel end-to-end in a consumer\'s own test suite'),
            new DocsPage('persistence', 'Persistence', 'The native MySQL/Postgres drivers, the Redis client, and TransactionGuard\'s commit/rollback protocol'),
            new DocsPage('query-builder', 'Query Builder', 'kinetis/query-builder: a thin, parameterized SQL query builder — not an ORM'),
            new DocsPage('migrations', 'Migrations', 'kinetis/migrations: a thin database migration runner — raw SQL up()/down(), no schema-diffing'),
            new DocsPage('queue', 'Queue', 'kinetis/queue: a backend-agnostic background job queue — every backend lives in its own separate package'),
            new DocsPage('queue-redis', 'Queue (Redis)', 'kinetis/queue-redis: a Redis backend for kinetis/queue\'s QueueInterface'),
            new DocsPage('queue-sql', 'Queue (SQL)', 'kinetis/queue-sql: a MySQL/Postgres backend for kinetis/queue\'s QueueInterface'),
            new DocsPage('queue-sqs', 'Queue (SQS)', 'kinetis/queue-sqs: an Amazon SQS backend for kinetis/queue\'s QueueInterface'),
            new DocsPage('queue-rabbitmq', 'Queue (RabbitMQ)', 'kinetis/queue-rabbitmq: a RabbitMQ backend for kinetis/queue\'s QueueInterface'),
            new DocsPage('auth', 'Authentication', 'kinetis/auth: opaque Bearer-token authentication middleware'),
            new DocsPage('auth-jwt', 'JWT Authentication', 'kinetis/auth-jwt: stateless JWT authentication (HS256/RS256), with optional per-token revocation'),
            new DocsPage('authorization', 'Authorization', 'kinetis/authorization: ability-based authorization — Gate wraps any callable Policy check'),
            new DocsPage('session', 'Sessions & CSRF', 'kinetis/session: cookie-backed sessions and CSRF protection — file, Redis, and SQL storage behind one store interface'),
            new DocsPage('runtime-adapters', 'Runtime Adapters', 'FrankenPHP, PHP-FPM, RoadRunner, and AWS Lambda adapters, and how RuntimeDetector picks one'),
            new DocsPage('concurrency', 'Concurrency', 'Fiber-based concurrency over Revolt: concurrently(), Async\\Socket, and Async\\Timer'),
            new DocsPage('caching', 'Caching & AOT Compilation', 'Production-only AOT caching of routes, commands, event listeners, and validation plans, plus APP_ENV'),
            new DocsPage('performance-tuning', 'Performance tuning', 'Capacity tuning: the worker-threads x maxConnections budget, what to observe under load, and tuning by workload shape'),
            new DocsPage('benchmarks', 'Benchmarks', 'Benchmark results against other PHP frameworks: what was tested and what came out'),
            new DocsPage('logging', 'Logging', 'PSR-3 logging: the default NullLogger, and where Kinetis logs on its own'),
            new DocsPage('telemetry', 'Telemetry', 'kinetis/telemetry: OpenTelemetry tracing — request spans, SQL/queue decorators, a traced HTTP transport, OTLP export over the Revolt-backed client'),
            new DocsPage('storage', 'Storage', 'kinetis/storage: file storage on League\\Flysystem — an Amp\\File-backed local adapter whose driver calls suspend the Fiber'),
            new DocsPage('storage-s3', 'Storage (S3)', 'kinetis/storage-s3: Amazon S3 (and S3-compatible) storage for kinetis/storage, over the Revolt-native transport in kinetis/revolt-http-client'),
            new DocsPage('mailer', 'Mailer', 'kinetis/mailer: mail sending via Symfony\\Component\\Mailer, non-blocking via kinetis/revolt-http-client for API-based transports'),
            new DocsPage('broadcasting', 'Broadcasting', 'kinetis/broadcasting: real-time broadcasting over the Pusher Channels protocol (Soketi/Reverb/Pusher), attribute-based private/presence channel authorization'),
            new DocsPage('revolt-http-client', 'HTTP Client', 'kinetis/revolt-http-client: a Revolt-native Symfony HttpClientInterface — usable standalone, no Kinetis required'),
            new DocsPage('redis', 'Redis Transport', 'kinetis/redis: a non-replaying, deadline-bounded Redis transport with TLS, authentication, and Redis Cluster slot routing — usable standalone'),
            new DocsPage('search', 'Search', 'kinetis/search: the shared search transport and configuration, and one engine-neutral client over OpenSearch and Elasticsearch'),
            new DocsPage('search-opensearch', 'Search (OpenSearch)', 'kinetis/search-opensearch: OpenSearch client construction, non-blocking via kinetis/revolt-http-client'),
            new DocsPage('search-elasticsearch', 'Search (Elasticsearch)', 'kinetis/search-elasticsearch: Elasticsearch client construction, non-blocking via kinetis/revolt-http-client'),
            new DocsPage('aws-sigv4', 'AWS request signing (SigV4)', 'kinetis/aws-sigv4: a PSR-18 client decorator signing requests with AWS Signature Version 4 — usable standalone'),
            new DocsPage('mcp', 'Model Context Protocol (MCP)', 'kinetis/mcp: the native Model Context Protocol server — tools and resources for your own application, over stdio and HTTP'),
            new DocsPage('mcp-docs', 'MCP Documentation Server', 'kinetis/mcp-docs: this server — what it exposes, how to install it, and how it reads pages'),
            new DocsPage('appendix', 'Appendix: System Layout', 'A dense, file-by-file reference across every namespace in core'),
            new DocsPage('appendix-packages', 'Appendix: Satellite Packages', 'A dense, file-by-file reference across every satellite package'),
            new DocsPage('appendix-ci', 'Appendix: Continuous Integration', 'What CI tests, how, and what is deliberately not covered'),
            new DocsPage('appendix-contributing', 'Appendix: Contributing to Kinetis', 'The monorepo layout, dev environment setup, testing, the manifest-driven release tooling, and the branching/PR/CI workflow'),
        ];
    }

    /**
     * The page $uri names, or null when no entry carries it — which is
     * what McpDocsServer turns into a -32002 "resource not found".
     */
    public static function find(string $uri): ?DocsPage
    {
        foreach (self::pages() as $page) {
            if ($page->uri() === $uri) {
                return $page;
            }
        }

        return null;
    }
}
