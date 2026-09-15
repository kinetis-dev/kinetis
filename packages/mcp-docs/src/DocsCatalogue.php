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
            new DocsPage('config', 'Configuration', '.env loading and typed Config access'),
            new DocsPage('bootstrapping', 'Bootstrapping', 'When to use bootstrap.php to bind application services and configure global middleware'),
            new DocsPage('routing-validation', 'Routing & Validation', 'Attribute-based routes, typed DTOs, constraint validation, and zero-config OpenAPI'),
            new DocsPage('middleware', 'Middleware', 'The PSR-15 middleware pipeline: global vs. route, plus the built-in ExceptionHandlerMiddleware/CorsMiddleware/RateLimitMiddleware'),
            new DocsPage('events', 'Events', 'A PSR-14 event dispatcher with attribute-driven listener registration, plus ShouldQueue for deferring a listener to a queue'),
            new DocsPage('cli', 'CLI', 'The kinetis CLI: built-in commands, application commands via #[Command], commands from installed packages, and restricting discovery'),
            new DocsPage('testing', 'Testing', 'TestClient, for exercising a Kernel end-to-end in a consumer\'s own test suite'),
            new DocsPage('persistence', 'Database', 'Install the database bridge, configure a connection, inject a SQL link and run transactions'),
            new DocsPage('query-builder', 'Query Builder', 'Build parameterized reads and writes, filter rows and hydrate result objects'),
            new DocsPage('orm', 'ORM', 'Define entities, use the request-scoped manager, query relationships and flush changes'),
            new DocsPage('migrations', 'Migrations', 'kinetis/migrations: a thin database migration runner — raw SQL up()/down(), no schema-diffing'),
            new DocsPage('queue', 'Queue', 'kinetis/queue: a backend-agnostic background job queue — every backend lives in its own separate package'),
            new DocsPage('queue-redis', 'Queue (Redis)', 'kinetis/queue-redis: a Redis backend for kinetis/queue\'s QueueInterface'),
            new DocsPage('queue-sql', 'Queue (SQL)', 'kinetis/queue-sql: a MySQL/Postgres backend for kinetis/queue\'s QueueInterface'),
            new DocsPage('queue-sqs', 'Queue (SQS)', 'kinetis/queue-sqs: an Amazon SQS backend for kinetis/queue\'s QueueInterface'),
            new DocsPage('queue-rabbitmq', 'Queue (RabbitMQ)', 'kinetis/queue-rabbitmq: a RabbitMQ backend for kinetis/queue\'s QueueInterface'),
            new DocsPage('auth', 'Authentication', 'kinetis/auth: opaque Bearer-token authentication middleware'),
            new DocsPage('auth-jwt', 'JWT Authentication', 'kinetis/auth-jwt: stateless JWT authentication (HS256/RS256), with optional per-token revocation'),
            new DocsPage('authorization', 'Authorization', 'Authorize a user against an application policy with Gate'),
            new DocsPage('session', 'Sessions & CSRF', 'Configure sessions, pass a CSRF token to a view and protect forms or JSON actions'),
            new DocsPage('runtime-adapters', 'Runtime Adapters', 'Run one application under FrankenPHP, PHP-FPM, RoadRunner or AWS Lambda'),
            new DocsPage('concurrency', 'Concurrency', 'Run independent I/O side by side and know when blocking calls prevent overlap'),
            new DocsPage('caching', 'Caching & AOT Compilation', 'Production-only AOT caching of routes, commands, event listeners, and validation plans, plus APP_ENV'),
            new DocsPage('views', 'Views', 'Engine-neutral rendering with pure PHP, Latte, or Twig, including asset URLs and compiled-template cache lifecycle'),
            new DocsPage('performance-tuning', 'Performance tuning', 'Capacity tuning: the worker-threads x maxConnections budget, what to observe under load, and tuning by workload shape'),
            new DocsPage('benchmarks', 'Benchmarks', 'Benchmark results against other PHP frameworks: what was tested and what came out'),
            new DocsPage('logging', 'Logging', 'PSR-3 logging: the default NullLogger, and where Kinetis logs on its own'),
            new DocsPage('telemetry', 'Telemetry', 'Configure OTLP tracing, wire outgoing clients and keep sensitive inputs out of spans'),
            new DocsPage('storage', 'Storage', 'Configure file storage, validate uploads and choose local disk or S3'),
            new DocsPage('storage-s3', 'Storage (S3)', 'kinetis/storage-s3: Amazon S3 (and S3-compatible) storage for kinetis/storage, over the Revolt-native transport in kinetis/revolt-http-client'),
            new DocsPage('mailer', 'Mailer', 'kinetis/mailer: mail sending via Symfony\\Component\\Mailer, non-blocking via kinetis/revolt-http-client for API-based transports'),
            new DocsPage('broadcasting', 'Broadcasting', 'kinetis/broadcasting: real-time broadcasting over the Pusher Channels protocol (Soketi/Reverb/Pusher), attribute-based private/presence channel authorization'),
            new DocsPage('revolt-http-client', 'HTTP Client', 'Send outbound requests, bound responses and handle uncertain writes'),
            new DocsPage('redis', 'Redis', 'Configure a Redis-backed cache, TLS or Cluster and use it in a Kinetis application'),
            new DocsPage('search-engines', 'Search engines', 'Connect to OpenSearch or Elasticsearch, index documents and run searches'),
            new DocsPage('search-opensearch', 'Search (OpenSearch)', 'kinetis/search-opensearch: OpenSearch client construction, non-blocking via kinetis/revolt-http-client'),
            new DocsPage('search-elasticsearch', 'Search (Elasticsearch)', 'kinetis/search-elasticsearch: Elasticsearch client construction, non-blocking via kinetis/revolt-http-client'),
            new DocsPage('aws-sigv4', 'AWS request signing (SigV4)', 'Sign requests for one trusted AWS origin, including Amazon OpenSearch Service'),
            new DocsPage('mcp', 'Model Context Protocol (MCP)', 'Expose application tools and resources over stdio or guarded HTTP'),
            new DocsPage('mcp-docs', 'MCP Documentation Server', 'Install the standalone server and read Kinetis pages as MCP resources'),
            new DocsPage('reference', 'Reference', 'Topic index for exact contracts, failure behavior and advanced framework wiring'),
            new DocsPage('container', 'Appendix: Container Lifecycle', 'AppScope and RequestScope resolution, ownership and disposal'),
            new DocsPage('appendix-configuration', 'Appendix: Configuration Reference', 'Configuration resolution, named connections and every framework key'),
            new DocsPage('appendix-runtime', 'Appendix: Runtime Reference', 'Request bodies, forwarded headers, adapters, Fibers and compiled artifacts'),
            new DocsPage('appendix-testing', 'Appendix: Testing Reference', 'Test-client request construction, loop liveness and runtime conformance'),
            new DocsPage('appendix-observability', 'Appendix: Observability Reference', 'Trace export, Fiber scope ownership and fingerprint construction'),
            new DocsPage('appendix-routing-validation', 'Appendix: Routing & Validation', 'Exact route matching, request binding, validation and OpenAPI rules'),
            new DocsPage('appendix-middleware', 'Appendix: Middleware', 'Pipeline order and the built-in middleware contracts'),
            new DocsPage('appendix-database', 'Appendix: Databases', 'Database drivers, pools, transactions and standalone connection use'),
            new DocsPage('appendix-query-builder', 'Appendix: Query Builder', 'Advanced SQL composition, row mapping and dialect behavior'),
            new DocsPage('appendix-redis', 'Appendix: Redis', 'Transport deadlines, Cluster routing and Redis cache mechanisms'),
            new DocsPage('appendix-queue', 'Appendix: Queue Contracts', 'Delivery and settlement contracts, worker lifecycle and backend mechanisms'),
            new DocsPage('appendix-sessions', 'Appendix: Sessions Reference', 'Session cookie, CSRF, expiry and store lifecycle'),
            new DocsPage('appendix-authentication', 'Appendix: Authentication', 'Bearer header grammar and JWT key, validation and failure contracts'),
            new DocsPage('appendix-storage', 'Appendix: Storage Reference', 'Local publication, path confinement and S3 failure behavior'),
            new DocsPage('appendix-search', 'Appendix: Search contracts', 'Search configuration, client results and transport failures'),
            new DocsPage('appendix-http-client', 'Appendix: HTTP client contracts', 'URL, header, deadline, response and transport contracts'),
            new DocsPage('appendix-aws-sigv4', 'Appendix: AWS SigV4 contracts', 'Signed-origin, credential, canonical request and failure contracts'),
            new DocsPage('appendix-mcp', 'Appendix: MCP Reference', 'Application MCP protocol, HTTP headers, errors and request lifecycle'),
            new DocsPage('appendix-mcp-docs', 'Appendix: MCP Documentation Server Reference', 'Documentation server installer, update, protocol and page fetch contracts'),
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
