# Appendix: Satellite Packages

A reference map of what exists in each optional satellite package, by
namespace. For core (`kinetis/framework` itself), see {doc}`appendix`.

## `packages/bref-adapter` (`kinetis/bref-adapter`)

Separate Composer package, not part of `kinetis/framework` core.

- `Kinetis\BrefAdapter\BrefLambdaAdapter implements Kinetis\Runtime\RuntimeAdapterInterface` — `run()` polls the Lambda Runtime API for the next invocation in a `while (true)` loop, converts the API Gateway HTTP API (payload format 2.0) event into a PSR-7 request, and posts the response back as the invocation's result; `isPersistent(): true` (a warm container keeps reusing the same process across invocations, the same shape `FrankenPhpAdapter` has). Talks to the Runtime API with plain stream-context HTTP, not `ext-curl`; a transport failure or a non-2xx status from the Runtime API itself throws rather than being treated as an empty response. Maps the event's top-level `cookies` list into a real `Cookie` header/`getCookieParams()` and `requestContext.http.sourceIp` into the request's `REMOTE_ADDR` server parameter — neither is in `headers`, and nothing else here has a real socket to read either from. `requestFromEvent()` decodes a base64 body strictly (invalid base64 throws, rather than being treated as an empty body); `responseToPayload()` base64-encodes a response body that isn't valid UTF-8 (`json_encode()` would otherwise reject it) and emits every `Set-Cookie` header value as its own entry in the payload's `cookies` array rather than folding them into one comma-joined header. Parses `multipart/form-data` via `riverline/multipart-parser`'s `StreamedPart`, `application/x-www-form-urlencoded` via `parse_str()` — a Lambda event body is one in-memory string with no live `php://input`, so `request_parse_body()` (what core's own adapters use) can't apply here. See {doc}`runtime-adapters` for the full supported/unsupported feature list.
- Depends on `kinetis/framework` (via a `path` repository to this monorepo's root), `nyholm/psr7`, `psr/http-message`, `riverline/multipart-parser`. Own `composer.json`/`phpunit.xml`/`phpstan.neon`.

## `packages/persistence` (`kinetis/persistence`)

Separate Composer package, not part of `kinetis/framework` core — extracted
from it so core itself has no direct MySQL/Postgres dependency.

- `Kinetis\Persistence\TransactionGuard` — request-scoped SQL transaction safety net. `transaction()` (commit on success, rollback on throw) and `beginTransaction()`/`rollbackDangling()` for the manual case. `Kinetis\Http\Kernel` and `bin/kinetis` both register `rollbackDangling()` as a dispose hook whenever this class is available (`class_exists()`-gated), not unconditionally — an application with no database can skip this package entirely.
- `Kinetis\Persistence\SqlConnectionFactory::fromConfig(Config $config, string $connection = 'default'): Contract\MysqlLink|Contract\PostgresLink` — builds a runtime-matched driver client from `DB_*` (or `DB_{NAME}_*` for a named connection); shared by `kinetis/migrations`' `migrate*` commands, `kinetis/queue`'s `QueueFactory`, and this package's own `PackageBootstrap`. `$poolOptions['warmConnections']`/`DB_WARM_CONNECTIONS` opens connections at construction via each driver's `warmUp(?int $connections = null)` — load-bearing for the mysqli driver under worker mode (see {doc}`performance-tuning`).
- `Kinetis\Persistence\Testing\DatabaseTransactions` / `DatabaseTruncation` — per-test database isolation for a consumer's PHPUnit suite (see {doc}`testing`): a rolled-back transaction per test, or explicit-table deletion before each test. Both ask the test for the connection via an abstract `databaseLink()`. `DatabaseTransactions` requires a single-connection (PDO) driver and skips otherwise — a transaction on one pooled connection isolates nothing the others do; `DatabaseTruncation` works with any driver and with code that opens its own transactions. `Testing\DatabaseIsolation` (@internal) holds their shared checks.
- `Kinetis\Persistence\Contract\PrefersPreparedStatements` — a marker, no methods: this link is faster binding a value than reading it as a literal. Carried by `PdoMysqlClient`/`PdoPgsqlClient` and their transactions, which memoize prepared statements per connection (and per transaction — a transaction owns its own handle, so it keeps its own cache) and therefore keep the binary protocol; the native drivers do not carry it, since an unparameterized query saves them a round trip. `Kinetis\QueryBuilder\Query` is the caller that branches on it.
- `Kinetis\Persistence\PackageBootstrap` — declared via `extra.kinetis`; with `DB_CONNECTION` set, binds `SqlConnectionFactory::fromConfig()`'s result under its dialect contract (`Contract\MysqlLink` or `Contract\PostgresLink`) before the application's own `bootstrap.php` runs (which wins on the same binding). Inert when `DB_CONNECTION` is unset; named (non-default) connections stay explicit app-side wiring.
- Depends on `kinetis/framework` (via a `path` repository to this monorepo's root) and `revolt/event-loop`; the drivers use `ext-mysqli`/`ext-pgsql`/PDO, suggested rather than required. Own `composer.json`/`phpunit.xml`/`phpstan.neon`.

## `packages/cache-redis` (`kinetis/cache-redis`)

Separate Composer package, not part of `kinetis/framework` core — extracted
from it for the identical reason `kinetis/persistence` was: core has no
direct Redis dependency either. `NullSimpleCache` and the PSR-16 exception
types stay in core (see {doc}`appendix`'s `Kinetis\SimpleCache` section);
only the classes with a real `amphp/redis` dependency moved.

- `Kinetis\SimpleCache\RedisSimpleCache` — single-node, backed by `Amp\Redis\RedisClient`. `fromConfig(Config $config, string $connection = 'default')`/`buildRedisConfig(Config $config, string $connection = 'default')` read `REDIS_URL` or discrete `REDIS_HOST`/`REDIS_PORT`/`REDIS_PASSWORD`/`REDIS_DATABASE`/`REDIS_TIMEOUT` (or their `REDIS_{NAME}_*` named-connection equivalents), returning `null` when neither is set. `clear()` flushes the entire selected database. Also implements core's `Kinetis\SimpleCache\AtomicCounterInterface`: `increment()` runs `INCR` and `EXPIRE` as one Lua script, so concurrent callers each receive a distinct value, and `count()` reads that counter — a bare integer, not a serialized value, so `get()` cannot read it. And `Kinetis\SimpleCache\AtomicConsumeInterface`: `consume()` runs `GET` and `DEL` as one Lua script, so at most one of two concurrent callers ever receives a given value.
- `Kinetis\SimpleCache\ClusteredRedisSimpleCache` — the Redis Cluster counterpart, activated by `REDIS_CLUSTER=true`/`REDIS_CLUSTER_SEEDS`. `Cluster\Crc16::slotFor()` computes the owning slot (CRC16-XMODEM mod 16384, honoring a `{...}` hash tag when present); `Cluster\ClusterTopology` discovers the slot→node layout via `CLUSTER SHARDS` and resolves a slot to the `RedisClient` that owns it, refreshing on a `MOVED` reply. `getMultiple()`/`deleteMultiple()` dispatch one command per key rather than a batched `MGET`/`DEL` (Redis Cluster rejects any multi-key command whose keys don't share a slot), run concurrently via `Kinetis\Async\concurrently()`; `clear()` fans `FLUSHDB` out to every master. Only database 0 is supported, matching a real cluster's own restriction. Implements `AtomicCounterInterface`/`AtomicConsumeInterface` too — each script carries one key, so it runs on whichever node owns that key's slot.
- `Kinetis\SimpleCache\Connection\TlsRedisConnector` — a `RedisConnector` using `Amp\Socket\connectTls()`, since `Amp\Redis`'s own default connector never upgrades to TLS. Shared by both classes above; returns `null` when `REDIS_TLS` isn't set.
- `Kinetis\Container\AppScope::boot()` tries `ClusteredRedisSimpleCache::fromConfig()` then `RedisSimpleCache::fromConfig()`, both `class_exists()`-gated against this package; Redis configured (`REDIS_HOST`/`REDIS_URL`/`REDIS_CLUSTER`) but this package not installed binds core's `UnavailableSimpleCache`, whose every operation throws `Kinetis\SimpleCache\Exception\SimpleCacheUnavailableException` naming this package — never a silent fallback to `NullSimpleCache`, and never a boot-time failure for an application that doesn't touch the cache.
- Depends on `kinetis/framework` (via a `path` repository to this monorepo's root), `amphp/redis`, `amphp/socket`, `amphp/serialization`. Own `composer.json`/`phpunit.xml`/`phpstan.neon`.

## `packages/mcp` (`kinetis/mcp`)

The Model Context Protocol server. Installing the package is the whole
setup: its `extra.kinetis` declares a scan root covering the namespace
and a bootstrap, so the `/mcp` route, `mcp:serve`, and the docs
resources all appear with nothing to wire. Core has no MCP surface
without it.

- `PackageBootstrap` — lazy-binds `McpServer`: discovery
  (`McpDiscovery::discover()`) runs when something first resolves the
  server — once per worker under a persistent runtime, once per `/mcp`
  request under PHP-FPM, never on a request that doesn't touch it. The
  project root comes from Composer's own runtime API
  (`InstalledVersions::getRootPackage()`), so the same code serves a
  consumer install and this package's own development.
- `Http\McpController` — `/mcp` as an ordinary route (`#[Post('/mcp')]`,
  `#[Middleware('@mcp')]`): parse-error handling, protocol-era
  detection, the mirrored-header checks (`MCP-Protocol-Version`/
  `Mcp-Method`/`Mcp-Name`, modern-era requests only, with the
  `=?base64?{...}?=` sentinel decoded and failing closed), the
  spec's error-code-to-HTTP-status mapping, `202` for notification-only
  bodies, and the SSE progress stream (a `StreamedResponse` whose
  emitter runs after the request scope is disposed, so the streamed
  call gets its own scope — with the request's `CurrentUserInterface`
  carried across — disposed after the final event). GET/DELETE declare
  no routes: the router's own `405` with `Allow: POST` is exactly what
  the 2026-07-28 spec asks for.
- `Http\McpOriginMiddleware` — the spec-required `Origin` validation,
  reading `MCP_ALLOWED_ORIGINS` (comma-separated exact list, empty
  means any request carrying an Origin is rejected `403`). A permanent
  `mcp`-group member at priority 100 — which is also what guarantees
  the group `McpController` references always exists.
- `Console\McpServeCommand` — `#[Command('mcp:serve')]`, resolving the
  bootstrap's own `McpServer` binding and handing the transport the
  real `AppScope` for per-message scopes.
- `McpServer` — handles one decoded JSON-RPC message; `handle()` takes an optional per-message scope threaded through to `McpDispatcher`. Supports the legacy (`2025-03-26`) `initialize` handshake and the modern (`2026-07-28`) stateless `server/discover` model side by side. `logger` param defaults to `NullLogger` (constructed directly, not through the container). A throwing tool reports `isError: true` with the fixed content string `Tool execution failed.`, the real exception going to the logger — a failed validation keeps its real `errors` map, since that's the argument feedback an agent retries on. `wrapModernResult()` adds `ttlMs`/`cacheScope` per `CACHEABLE_METHOD_SCOPES` (`server/discover`/`tools/list`/`resources/list` → `public`, `resources/read` → `private`, `tools/call` → neither, since it's an action not a cacheable read). The optional constructor `$instructions` is included on `server/discover` only when given, omitted entirely otherwise.
- `McpRegistry` — `#[McpTool]`/`#[McpResource]` discovery, `toArray()`/`fromArray()` for the AOT cache.
- `McpDispatcher` — the MCP analogue of `Http\Dispatcher`. `callTool()`/`readResource()` take an optional per-call scope the transports create per message; the controller and its dependencies resolve from it, falling back to the constructor's container when none is given (which is then not per-message-scoped).
- `ProgressReporter` — injected by type into a tool method; `report()` streams a `notifications/progress` event when `_meta.progressToken` is present, a no-op otherwise.
- `Transport\StdioTransport` — one JSON-RPC message per line on stdin/stdout. Given an `AppScope` (as `mcp:serve` passes), each line is a unit of work: fresh scope, the `TransactionGuard` rollback hook behind the same `class_exists()` gate `Kernel` uses, disposal once the response is written, then `gc_collect_cycles()` — a stdio server is a persistent process. Without one, messages share the dispatcher's own container, the pre-scope behavior.
- `KinetisDocsResource` — registers every `docs/*.md` page as an MCP resource (`kinetis://docs/{slug}`) — the monorepo's own files when developing Kinetis, the published documentation otherwise. Lives under this package's scan root, so discovery always finds it on both transports; registering it manually (`$registry->register(KinetisDocsResource::class)`) is only needed for a hand-wired `McpRegistry` that never goes through discovery.
- `McpDiscovery::discover(string $projectRoot, ?array $paths = null): McpRegistry` — builds a registry from every class found anywhere under a project's own PSR-4 root(s), plus `Kinetis\Mcp` itself (`NamespaceScanner`, see `Kinetis\Cache` below), rather than an explicit registration file. `$paths`, or `MCP_DISCOVERY_PATHS` when omitted, restricts the project-side scan.

## `packages/migrations` (`kinetis/migrations`)

Separate Composer package, not part of `kinetis/framework` core.

- `Kinetis\Migrations\Migration` — the interface a migration file's anonymous class implements: `up(MysqlLink|PostgresLink $db): void`/`down(...): void`, raw SQL only, issued via `$db->execute()`.
- `Kinetis\Migrations\MigrationFile` — discovers `<timestamp>_<description>.php` files under a `migrations/` project-root directory, sorted by filename; `load()` is a bare `require` of the file.
- `Kinetis\Migrations\MigrationRepositoryInterface` / `SqlMigrationRepository` — tracks applied migrations in a `kinetis_migrations` table (`migration` primary key, `applied_at`), typed against the generic `Kinetis\Persistence\Contract\SqlLink` since its own bookkeeping SQL is dialect-agnostic.
- `Kinetis\Migrations\MigrationRunner` — `pending()`/`migrate()`/`rollback()`/`status()`. Never wraps a migration in a transaction; `rollback()` targets the single most recently applied migration only, throwing `Exception\MigrationFileMissingException` if that migration's file no longer exists. `migrate()`/`rollback()` hold a cross-process advisory lock (MySQL `GET_LOCK()`, Postgres `pg_advisory_lock()`) for their whole duration, throwing `Exception\MigrationLockTimeoutException` if it can't be acquired within `$lockTimeoutSeconds` (10 by default).
- `Kinetis\Migrations\MigrationScaffolder` — writes a new timestamped migration file with the `up()`/`down()` stubs filled in, via an exclusive (`x`) file create rather than an unconditional overwrite; a same-second name collision retries with a random suffix. Throws `Exception\MigrationScaffoldException` on a real I/O failure creating the directory or writing the file.
- `Kinetis\Migrations\Console\{MigrateCommand, RollbackCommand, StatusCommand, MakeCommand}` — the `migrate`/`migrate:rollback`/`migrate:status`/`migrate:make <description>` commands on `vendor/bin/kinetis`, registered through this package's `extra.kinetis` scan root and all `#[Command(bootstrap: false)]`. `Console\MigrationContext` (@internal) is their shared connection/paths holder: connects via `Kinetis\Persistence\SqlConnectionFactory`, reading `DB_CONNECTION` (`mysql`|`pgsql`, required) plus `DB_HOST`/`DB_NAME`/`DB_USER`/`DB_PASSWORD`/`DB_PORT`; `--connection=<name>` wins over `MIGRATE_CONNECTION_NAME` (default `'default'`) for a named connection.
- Depends on `kinetis/framework` and `kinetis/persistence` (both via a `path` repository to this monorepo's root); `SqlMigrationRepository` types against the generic `Kinetis\Persistence\Contract\SqlLink`. Own `composer.json`/`phpunit.xml`/`phpstan.neon`.

## `packages/query-builder` (`kinetis/query-builder`)

Separate Composer package, not part of `kinetis/framework` core.

- `Kinetis\QueryBuilder\Query` — a thin, parameterized SQL query builder, not an ORM (no relationships/migrations/change-tracking). One class works with either MySQL or Postgres via the shared `Kinetis\Persistence\Contract\SqlLink` family (auto-detected instanceof `Contract\MysqlLink`/`Contract\PostgresLink`). `select()`/`selectRaw()`/`where()`/`orWhere()`/`whereIn()`/`whereRaw()`/`join()`/`leftJoin()`/`orderBy()`/`orderByRaw()`/`limit()`/`offset()`, terminal `get()`/`first()`/`count()` (optional `Hydrator`-based DTO mapping) and `insert()`/`insertGetId()`/`update()`/`delete()`. Accepts a plain pool or an in-flight `SqlTransaction`, so it composes inside `TransactionGuard::transaction()`. Every terminal method routes through one private `run()`, which chooses between `$link->query()` and `$link->execute()` per driver: a query with nothing bound always takes `query()`, and a query whose every value is an `int` or `bool` writes them as literals and takes it too — unless the link carries `Contract\PrefersPreparedStatements`, which the PDO drivers do, where binding is the cheaper of the two. Strings, `null` and floats always bind, and any use of `whereRaw()`/`selectRaw()`/`orderByRaw()` disables inlining for the whole query.
- `Kinetis\QueryBuilder\Dialect` (+ `Dialect\MySqlDialect`/`Dialect\PostgresDialect`) — isolates identifier quoting, retrieving a generated primary key after an insert (MySQL: `getLastInsertId()`; Postgres: `INSERT ... RETURNING`), and `literalFor()`, the per-dialect logic behind the literal-inlining above.
- `Kinetis\QueryBuilder\CompiledQuery` — the `{sql, params}` output of every `to*Sql()` compile method, built together in one pass so bound parameters always land in the same position as their `?` in the generated SQL, even once `whereRaw()`/`whereIn()` mix with structured `where()` calls.
- `Query::paginate(int $perPage, int $page = 1, ?string $dtoClass = null): Kinetis\Http\Pagination\Paginator` — a `count()` for `total`/`lastPage` plus a `limit()`/`offset()`-based `get()` for the page, against the same `where()`/`join()` filters already on the query. A page past the last one returns empty `data` with the real `total` still reported, not an error.
- `Query::cursorPaginate(int $perPage, ?string $cursor, string $cursorColumn = 'id', ?string $dtoClass = null, ?string $cursorAlias = null): Kinetis\Http\Pagination\CursorPaginator` — orders by `$cursorColumn` and filters `WHERE $cursorColumn > $cursor` once one is given (`null` fetches from the start); no `COUNT(*)`, no page number. `nextCursor` always comes out of the same result as the delivered rows — one query, never two, so a write landing between reads can't leave the cursor naming a row the caller was never handed. Always fetches raw rows first regardless of `$dtoClass`, so the cursor is read off the real column name rather than a hydrated DTO's own property name. A `select()` projection that omits an *unqualified* `$cursorColumn` still works: it's added to the query automatically and stripped back out of every returned row (and never reaches `$dtoClass` hydration) before returning. A *qualified* `$cursorColumn` (`orders.id`, for a `join()`ed query) requires `$cursorAlias`: both MySQL and Postgres report it under its bare name, which a join collides with, and no alias this class could pick is guaranteed absent from an arbitrary projection — so the caller names one, the column is additionally selected under it, and it is stripped from every returned row. Omitting it for a qualified column throws `InvalidArgumentException`, as does an alias matching a column the caller listed in `select()` (checked before any SQL runs). An alias colliding with a column only a wildcard brings in is a documented caller precondition rather than a check: it replaces that column, since detecting it would need column metadata `SqlResult` doesn't carry and the one available proxy also fires on the ordinary duplicate `id` of a `SELECT *` across a join. Everything else about the query is untouched, so an alias an `orderBy()` depends on and a caller's own `offset()` both survive.
- One `Query` instance is one query — nothing resets between fluent calls; construct a fresh instance per query.
- Depends on `kinetis/framework` and `kinetis/persistence` (via `path` repositories to this monorepo's root). Own `composer.json`/`phpunit.xml`/`phpstan.neon`.

## `packages/queue` (`kinetis/queue`)

Separate Composer package, not part of `kinetis/framework` core.

- `Kinetis\Queue\Job` — a marker interface (no declared methods) a job class implements. `handle()` is discovered and invoked by reflection, not a fixed interface method, since its parameter list varies per job.
- `Kinetis\Queue\QueueInterface` — `push(Job $job, int $delaySeconds = 0, string $queue = 'default', ?int $maxAttempts = null): void`, `pop(int $timeoutSeconds = 0, array $queues = ['default']): ?QueuedJob`, `ack(QueuedJob $job): void`, `release(QueuedJob $job): void`, `fail(QueuedJob $job): void`, `size(string $queue = 'default'): int` (jobs waiting to be popped — delayed included, reserved excluded), `clear(string $queue = 'default'): int` (discards waiting jobs, returning how many; reserved jobs are untouched). `$queues` is checked in the given order — priority by list position, not a numeric score. `$maxAttempts` null (the default) defers to the processing `QueueWorker`'s own `$defaultMaxAttempts`, which is never itself unlimited; once `QueuedJob::$attempts` reaches the effective cap, `fail()` removes the job permanently instead of `release()` retrying it.
- `Kinetis\Queue\QueuedJob` — `{class, args, handle, queue, attempts, maxAttempts, metadata}`. `$attempts` is the attempt number the current `pop()` represents (1-indexed), not a raw failure count. `$metadata` is opaque string metadata stored at push time — the instrumentation propagation channel, carried verbatim by every backend.
- `Kinetis\Queue\JobSerializer` — converts a `Job` instance to plain `{class, args}` data (reading each constructor parameter's value off a same-named property via reflection) and back via `new $class(...$args)`. Throws `Exception\UnserializableJobException` for a constructor parameter with no matching property. `redact(string $class, array $args): array` returns those arguments with every value whose constructor parameter carries `Attributes\Sensitive` replaced by `JobSerializer::REDACTED` (`[redacted]`), for logging; a class that no longer loads redacts every value rather than none.
- `Kinetis\Queue\Attributes\Sensitive` — `TARGET_PARAMETER`, no arguments. Marks a job constructor parameter whose value must never reach a log; affects logging only, never what is written to the backend. Redacts an array or object value whole, with no per-element redaction within one.
- `Kinetis\Queue\JobInvoker` — `invoke(Job $job, ContainerInterface $container): void`, reflecting and calling `handle()` with each parameter resolved through the given container. Shared by `QueueWorker` and `SyncQueue`.
- `Kinetis\Queue\RedisQueue` — backed by `Amp\Redis\RedisClient`. Uses the "reliable queue" pattern (`popTailPushHeadBlocking()`, i.e. `BRPOPLPUSH`) rather than a plain destructive pop, moving a job to a separate processing list until `ack()`/`release()`; delayed jobs live in a sorted set scored by ready-at time, promoted in bounded batches (`DELAYED_PROMOTION_BATCH_SIZE`) once per `pop()` call, so a large ready backlog can't stall other Redis clients for one script's whole duration. `release()` (processing → pending) and delayed-job promotion (delayed → pending) both run as a single Lua script (`RedisClient::eval()`) rather than a remove-then-push pair of commands, so a process crash can never land between the two halves of either move; `release()`'s script is also conditional on actually having found and removed the source entry, so a duplicate call or a retry after an ambiguous connection failure throws `Exception\StaleJobHandleException` (which `QueueWorker` treats as benign) instead of enqueueing a second replacement. Every envelope carries a random `id` (and a `pushedAt` timestamp), generated fresh only on an independent `push()` — `release()` preserves the `id`/`pushedAt` it reads back off the envelope it's replacing, keeping the job's own logical identity and original enqueue time stable across retries. `id` is what keeps two byte-identical jobs from colliding into one member when both land in the delayed sorted set — sorted-set members are unique, plain strings are not. A job written by the envelope format that predates `id`/`pushedAt` is still release()-able after an upgrade: both fields are read optionally, falling through to a freshly generated value the first time such a job is released, rather than depending on a key that older format never wrote.
- `Kinetis\Queue\SqlQueue` — backed by the generic `Kinetis\Persistence\Contract\SqlLink` (dialect-agnostic SQL, including priority ordering via `CASE queue WHEN ... END`). Dequeues via `SELECT ... FOR UPDATE SKIP LOCKED` inside a transaction; `pop()`'s blocking contract is a poll loop suspended with `Kinetis\Async\Timer::delay()`, since SQL has no native blocking-wait primitive. Requires the `kinetis_queue_jobs` table (with `queue`, `attempts`, `max_attempts` columns and a composite `(queue, available_at, reserved_at)` index) — see `resources/migrations/create_kinetis_queue_jobs_table.{mysql,pgsql}.php.stub`, not auto-created. `fail()` deletes the row, the same as `ack()`.
- `Kinetis\Queue\SyncQueue` — runs `push()`'s job immediately, inline, via `JobInvoker`; `pop()` always returns `null`, `ack()`/`release()`/`fail()` are no-ops. For local development; not selectable via `QUEUE_CONNECTION`. A fresh `RequestScope` per `push()`, same as `QueueWorker`; unlike `QueueWorker`, a failing job's exception propagates rather than being caught and logged.
- `Kinetis\Queue\QueueWorker` — `__construct(AppScope $app, QueueInterface $queue, int $defaultMaxAttempts = 0)`, `run()`/`processNext()`/`stop()`. SIGTERM/SIGINT (via `ext-pcntl`, when loaded — `supportsGracefulShutdown()`) stop `run()`'s loop after the job in flight finishes, so a deploy never truncates a job. One fresh `RequestScope` per job via `AppScope::createRequestScope()`, `handle()`'s parameters autowired through it via `JobInvoker`. A throwing job is always logged (job class, queue, attempt number, and the exception, plus the arguments — redacted per `Attributes\Sensitive` — only when the job is being given up on, since a job about to be retried still holds its payload in the backend); the effective cap is `QueuedJob::$maxAttempts ?? $defaultMaxAttempts` — released while `$attempts` is below it, `fail()`ed once reached. `$defaultMaxAttempts` is non-nullable: there is no configuration on this class that produces unlimited retries by default.
- `Kinetis\Queue\QueuedListenerInvoker` — implements core's `Kinetis\Events\ListenerInvokerInterface`. Serializes the event (via `JobSerializer`, generalized to accept any `object`, not `Job` specifically) and pushes an `InvokeListenerJob` carrying the listener's class/method as plain strings.
- `Kinetis\Queue\InvokeListenerJob` — the job `QueuedListenerInvoker` pushes. `handle(RequestScope $scope)` resolves the listener through the given scope and reconstructs the event via `JobSerializer::deserialize()`, invoking the original method by name.
- `Kinetis\Queue\QueueFactory::fromConfig(Config): QueueInterface` — builds the backend `QUEUE_CONNECTION` selects (`redis`|`sql`|`sqs`|`rabbitmq`, required) from the matching `REDIS_*`/`DB_*` convention, or `QUEUE_SQS_*`/`QUEUE_RABBITMQ_*` (`packages/queue-sqs`/`packages/queue-rabbitmq`, below); `QUEUE_CONNECTION_NAME` (default `'default'`) selects a named connection of that backend. `sqs`/`rabbitmq` are each `class_exists()`-gated against their own package's client-factory class; throws `Exception\QueueUnavailableException` naming the missing package when it isn't installed.
- `Kinetis\Queue\PackageBootstrap` — declared via `extra.kinetis`; with `QUEUE_CONNECTION` set, binds `QueueInterface` to `QueueFactory::fromConfig()`'s result before the application's own `bootstrap.php` runs (which wins on the same binding). Inert when `QUEUE_CONNECTION` is unset.
- `Kinetis\Queue\Console\WorkCommand` — the `queue:work [--queue=high,default]` command on `vendor/bin/kinetis`, registered through this package's `extra.kinetis` scan root. Constructor-injects `QueueInterface` (the `PackageBootstrap` binding, or the application's override) plus `Config` for `QUEUE_POLL_TIMEOUT` and `QUEUE_MAX_ATTEMPTS` (passed through as `QueueWorker`'s `$defaultMaxAttempts`, both defaulting to `5`/`0` respectively). Warns on STDERR at startup when `ext-pcntl` is missing, since graceful shutdown is impossible without it.
- `Kinetis\Queue\Console\StatsCommand` / `ClearCommand` — `queue:stats [--queue=high,default]` (waiting counts per queue, with a total) and `queue:clear --queue=<name> --force` (discards waiting jobs; refuses without `--force`, exit 1). Both drive the `QueueInterface` binding, so they report on whichever backend `QUEUE_CONNECTION` selects.
- Depends on `kinetis/framework`, `kinetis/persistence` (`SqlQueue`'s `TransactionGuard` use), and `kinetis/cache-redis` (`QueueFactory`'s `RedisSimpleCache::buildRedisConfig()` reuse) — all via a `path` repository to this monorepo's root — plus `amphp/redis`, `psr/log` (`QueueWorker`'s failure logging), `psr/container` (`JobInvoker`'s container parameter). Own `composer.json`/`phpunit.xml`/`phpstan.neon`.

## `packages/queue-sqs` (`kinetis/queue-sqs`)

Separate Composer package, not part of `kinetis/framework` core.

- `Kinetis\QueueSqs\SqsQueue implements Kinetis\Queue\QueueInterface` — backed by `AsyncAws\Sqs\SqsClient`. `push()`/`pop()` map onto `SendMessage`/`ReceiveMessage`; `ack()`/`fail()` onto `DeleteMessage`; `release()` onto `ChangeMessageVisibility` with `VisibilityTimeout: 0` (immediately available again, rather than waiting out the normal timeout). A queue name resolves to an SQS queue of that name (optionally prefixed) via `GetQueueUrl`, cached per instance — never auto-created. `delaySeconds` uses SQS's own native `SendMessage` delay, capped at 900 seconds — a longer value throws before any network call. `QueuedJob::$attempts` comes directly from SQS's own `ApproximateReceiveCount` message attribute; `$maxAttempts` (no native SQS equivalent) travels as a custom `maxAttempts` message attribute; instrumentation propagation metadata travels the same way, as one JSON-encoded `metadata` attribute (see the telemetry package's `OtelTelemetry` above). `pop()`'s multi-queue priority cycling uses a short, fixed per-queue `WaitTimeSeconds` (SQS's own long-polling primitive, capped at 20 seconds) — no `Kinetis\Async\Timer::delay()` or `concurrently()` wrapper, since the injected `AmpHttpClient` transport tolerates being called from plain top-level code. Standard SQS queues only; FIFO is not supported.
- `Kinetis\QueueSqs\SqsClientFactory::fromConfig(Config $config, string $connection = 'default'): SqsClient` — builds `SqsClient` with `Kinetis\RevoltHttpClient\AmpHttpClientFactory::create()` injected as its transport. `QUEUE_SQS_REGION` required; `QUEUE_SQS_ENDPOINT`/`QUEUE_SQS_QUEUE_PREFIX` optional, all via `Config::scopedKey()`. Credentials are never read from `Kinetis\Config` — left to AsyncAws's own default credential provider chain.
- `kinetis/queue`'s `QueueFactory` dispatches to this package for `QUEUE_CONNECTION=sqs` (see above).
- Depends on `kinetis/framework`, `kinetis/queue`, `kinetis/persistence`, `kinetis/cache-redis` (both transitively required by `kinetis/queue` itself, but declared directly here too so Composer's dependency resolution can find them), and `kinetis/revolt-http-client` (all via `path` repositories), `async-aws/sqs`. Own `composer.json`/`phpunit.xml`/`phpstan.neon`.

## `packages/queue-rabbitmq` (`kinetis/queue-rabbitmq`)

Separate Composer package, not part of `kinetis/framework` core.

- `Kinetis\QueueRabbitMq\RabbitMqQueue implements Kinetis\Queue\QueueInterface` — backed by `Thesis\Amqp\Client`/`Channel`. A queue is declared durable on first touch by any method, never auto-created ahead of that. `push()` publishes to the queue directly; a delayed `push()` instead publishes to a dedicated `{queue}.delay` queue configured with `x-dead-letter-exchange`/`x-dead-letter-routing-key` pointing back at the real queue and a per-message `expiration` equal to the delay, so RabbitMQ itself moves the message once it expires — no polling-based promotion. `attempts`/`maxAttempts` travel as plain message headers (AMQP 0-9-1 has no native attempt count, only a boolean `redelivered` flag), and instrumentation propagation metadata as a JSON-encoded `metadata` header carried forward by `release()`; `release()` republishes with an incremented `attempts` header before discarding the original delivery via `nack(requeue: false)`, since `nack`'s own `requeue` flag redelivers the message unchanged. `QueuedJob::$handle` is the `Thesis\Amqp\DeliveryMessage` itself. `pop()`'s multi-queue priority cycling uses `basic.get` (a single, immediate, non-blocking request per queue — AMQP has no native blocking-wait-with-timeout primitive), sleeping via `Amp\delay()` between full sweeps when nothing is found. One channel per instance, opened lazily and reused. `Kinetis\Async\concurrently()` composes correctly with a still-open connection, confirmed against a real broker — `ConcurrentBatch` parks on a targeted Revolt suspension resumed once its own tasks finish, unaffected by `Thesis\Amqp\Channel`'s permanent background reader.
- `Kinetis\QueueRabbitMq\RabbitMqClientFactory::fromConfig(Config $config, string $connection = 'default'): Client` — builds `Thesis\Amqp\Client` from `Thesis\Amqp\Config::fromURI()`. `QUEUE_RABBITMQ_URL` required, via `Config::scopedKey()`.
- `kinetis/queue`'s `QueueFactory` dispatches to this package for `QUEUE_CONNECTION=rabbitmq` (see above).
- Depends on `kinetis/framework`, `kinetis/queue`, `kinetis/persistence`, and `kinetis/cache-redis` (the last two transitively required by `kinetis/queue` itself, but declared directly here too so Composer's dependency resolution can find them) — all via `path` repositories — plus `thesis/amqp`. Own `composer.json`/`phpunit.xml`/`phpstan.neon`.

## `packages/storage` (`kinetis/storage`)

Separate Composer package, not part of `kinetis/framework` core.

- `Kinetis\Storage\AmpFileAdapter` — a `League\Flysystem\FilesystemAdapter` for local disk backed by `Amp\File\Filesystem` instead of Flysystem's own local adapter, so every operation suspends the calling Fiber via Revolt rather than blocking the worker. `readStream()` is the one exception: it reads the whole file via the same non-blocking primitive, then buffers it into an in-memory `php://temp` resource, since a PHP `resource` can't lazily pull from a userland object without a registered stream wrapper. `write()`/`writeStream()`/`copy()` stream genuinely, via `Amp\ByteStream\pipe()` between real `Amp\File\File` handles. Rejects a symlink observed at check time: every path is checked component by component (`Amp\File\Filesystem::isSymlink()`, lstat semantics) before the real operation runs, and a discovered symlink throws `League\Flysystem\SymbolicLinkEncountered` rather than being resolved — a check-then-use guard, not a race-free one, so `FILESYSTEM_ROOT` is a real boundary only when this adapter is the sole writer to it; see {doc}`storage`'s "Symlink checks" section for the full threat model and the operational mitigation for a shared root. `deleteDirectory()` plans the whole subtree before deleting anything, so a symlink found partway through leaves nothing deleted rather than the entries visited earlier already gone.
- `Kinetis\Storage\PackageBootstrap` — declared via `extra.kinetis`; with `FILESYSTEM_DRIVER` set, lazily binds `League\Flysystem\FilesystemOperator` to `FilesystemFactory::fromConfig()`'s result before the application's own `bootstrap.php` runs (which wins on the same binding). Inert when `FILESYSTEM_DRIVER` is unset; named connections stay explicit app-side wiring.
- `Kinetis\Storage\FilesystemFactory::fromConfig(Config $config, string $connection = 'default'): League\Flysystem\Filesystem` — `FILESYSTEM_DRIVER` (default `'local'`) and `FILESYSTEM_ROOT` (required for the local driver), both via `Config::scopedKey()` for named connections. `FILESYSTEM_DRIVER=s3` dispatches to `packages/storage-s3` (below) if installed, else throws `Exception\StorageUnavailableException`.
- Depends on `kinetis/framework` (via a `path` repository), `league/flysystem`, `league/mime-type-detection` (`FinfoMimeTypeDetector`), `amphp/file`, `amphp/byte-stream` (`AmpFileAdapter`'s streaming `write()`/`writeStream()`/`copy()`, via `Amp\ByteStream\pipe()`). Own `composer.json`/`phpunit.xml`/`phpstan.neon`.

## `packages/revolt-http-client` (`kinetis/revolt-http-client`)

Separate Composer package, not part of `kinetis/framework` core — and,
unlike every other satellite package, not dependent on it either:
`kinetis/framework` appears only in `require-dev` (for tests and the
`NoStaticPropertiesRule` dogfooding), never in `require`. Genuinely
installable and usable with no Kinetis framework present at all.

- `Kinetis\RevoltHttpClient\Http` — the client application code uses. Immutable: `withBaseUrl()`/`withToken()`/`withBasicAuth()`/`withHeaders()`/`withQuery()`/`withTimeout()`/`withRetries()`/`asForm()` each return a new instance. `get()`/`post()`/`put()`/`patch()`/`delete()` take arrays (query for `get()`, JSON body for the rest); `send()` is the general form taking Symfony HttpClient options. Constructed with no argument it defaults to `AmpHttpClientFactory::create()`, so it autowires; pass any `HttpClientInterface` (Symfony's `MockHttpClient`, for one) to substitute the transport. `withRetries()` wraps the transport in Symfony's own `RetryableHttpClient` rather than hand-rolled retry logic.
- `Kinetis\RevoltHttpClient\HttpResponse` — `status()`/`successful()`/`failed()`/`clientError()`/`serverError()`/`body()`/`json()`/`jsonPath()`/`header()`. An error status is returned rather than thrown; `throw()` opts into raising `Exception\HttpRequestException` and returns the response otherwise, so it chains. `getMessage()` deliberately excludes the response body, the request URL's userinfo/query string, and any lower-level transport exception's own message — any of those could carry a secret (a signed URL's signature, an API key, PII in an upstream error body, a credential embedded in a transport client's own error text) that routine exception logging would otherwise leak; the full, unredacted detail is still reachable via `diagnosticUrl()`/`diagnosticBody()`/`diagnosticMessage()`/`getPrevious()`, but only by an explicit call — the first two are accessor *methods* over private fields, not public properties, so a generic serializer like `json_encode($e)` never exposes them either. A transport failure — no response at all — throws the same exception type with status `0`, so one catch covers everything the client throws. The body is read once and cached, and reading is deferred until asked for, which is what lets requests started inside `concurrently()` overlap.
- `Kinetis\RevoltHttpClient\AmpHttpClientFactory::create(array $defaultOptions = [], ?callable $clientConfigurator = null, int $maxHostConnections = 6, int $maxPendingPushes = 50): Symfony\Contracts\HttpClient\HttpClientInterface` — mirrors `Symfony\Component\HttpClient\AmpHttpClient`'s own constructor exactly, no Kinetis-specific defaults layered on top.
- Depends on `symfony/http-client` (`^8.0` — the first version whose `AmpHttpClient` targets the current, Revolt-based `amphp/http-client` generation rather than the old pre-Fiber one), `symfony/http-client-contracts`, and `amphp/http-client` (`^5.3`, an optional peer dependency of `symfony/http-client` that isn't auto-installed, so declared directly). Own `composer.json`/`phpunit.xml`/`phpstan.neon`.

## `packages/aws-sigv4` (`kinetis/aws-sigv4`)

Separate Composer package, not part of `kinetis/framework` core — and,
like `kinetis/revolt-http-client`, not dependent on it either:
`kinetis/framework` appears only in `require-dev`.

- `Kinetis\AwsSigV4\SigV4SigningClient implements Psr\Http\Client\ClientInterface` — the package's main class. Wraps another PSR-18 client and signs every request with AWS Signature Version 4 before delegating to it, reusing `AsyncAws\Core\Signer\SignerV4` directly (the same signer every AsyncAws service client already uses internally) rather than reimplementing the algorithm. Converts a PSR-7 request to `AsyncAws\Core\Request`, resolves credentials via `AsyncAws\Core\Credentials\ChainProvider::createDefaultChain()` (wrapped in `CacheProvider`) unless a `CredentialProvider` is passed directly, signs, and copies the resulting headers back onto a PSR-7 request. The constructor's own `?\DateTimeImmutable $now` parameter exists solely for testability (`sendRequest()`'s signature is fixed by the PSR-18 interface, so there's nowhere else to thread a fixed clock through) — real usage always leaves it `null`. Before signing, the request's body is always replaced with a `SpooledStream` (below) — sourced by rewinding-then-reading a seekable original body, or reading a non-seekable one from wherever it already is (seeking one backward is impossible by definition) — so neither the signature computation nor the wrapped client's own read ever has to seek the caller's original stream. A seekable original body's own cursor position is saved before reading and restored afterward (success or failure), since it's the same stream object the caller's own request was built with, not a private copy.
- `Kinetis\AwsSigV4\SpooledStream implements Psr\Http\Message\StreamInterface` — `@internal`, constructed only by `SigV4SigningClient` itself. A minimal, always-seekable PSR-7 stream over an already-in-memory string, backed by `php://temp` (in memory up to 2MB, then a real temp file) so this stream's own storage doesn't hold a second long-lived full copy of the body — it does not bound the *peak* memory a signed request costs, since the body is still read into a plain PHP string more than once along the way (once to build this stream, again to compute the signature); avoids this package taking on a full PSR-7 implementation as a runtime dependency either way.
- Its own test suite includes AWS's published "get-vanilla" SigV4 test vector (a fixed date and static test credentials, `AKIDEXAMPLE`) and matches the published expected `Authorization` header exactly, plus a non-seekable request body and a body past `php://temp`'s in-memory threshold, both signed and sent correctly, and a seekable body's own cursor position confirmed restored to exactly where it started after signing.
- Depends on `kinetis/revolt-http-client`, `async-aws/core`, `psr/http-client`, `psr/http-message`. Own `composer.json`/`phpunit.xml`/`phpstan.neon`.

## `packages/storage-s3` (`kinetis/storage-s3`)

Separate Composer package, not part of `kinetis/framework` core.

- `Kinetis\StorageS3\S3FilesystemFactory::fromConfig(Config $config, string $connection = 'default'): League\Flysystem\Filesystem` — builds `AsyncAws\S3\S3Client` with `Kinetis\RevoltHttpClient\AmpHttpClientFactory::create()` injected as its transport, wraps it in `League\Flysystem\AsyncAwsS3\AsyncAwsS3Adapter`. `FILESYSTEM_S3_BUCKET`/`FILESYSTEM_S3_REGION` required; `FILESYSTEM_S3_PREFIX`/`FILESYSTEM_S3_ENDPOINT`/`FILESYSTEM_S3_PATH_STYLE` optional, all via `Config::scopedKey()`. Credentials are never read from `Kinetis\Config` — left to `AsyncAws\Core\Configuration`'s own default credential provider chain.
- `kinetis/storage`'s own `Kinetis\Storage\FilesystemFactory` dispatches to this package for `FILESYSTEM_DRIVER=s3`, `class_exists()`-gated; throws `Kinetis\Storage\Exception\StorageUnavailableException` naming this package when it isn't installed.
- Depends on `kinetis/framework` and `kinetis/revolt-http-client` (both via `path` repositories), `async-aws/s3`, `league/flysystem-async-aws-s3`. Own `composer.json`/`phpunit.xml`/`phpstan.neon`.

## `packages/mailer` (`kinetis/mailer`)

Separate Composer package, not part of `kinetis/framework` core.

- `Kinetis\Mailer\PackageBootstrap` — declared via `extra.kinetis`; with `MAILER_DSN` set, lazily binds `Symfony\Component\Mailer\MailerInterface` to `MailerFactory::fromConfig()`'s result. Inert when `MAILER_DSN` is unset. The binding resolves in whichever process injects it, so a queued job's `handle()` gets it in the worker.
- `Kinetis\Mailer\MailerFactory::fromConfig(Config $config, string $connection = 'default'): Symfony\Component\Mailer\MailerInterface` — the only class in the package. Reads a single `MAILER_DSN` (`Config::scopedKey()` for named connections) and always passes `Kinetis\RevoltHttpClient\AmpHttpClientFactory::create()` into `Symfony\Component\Mailer\Transport::fromDsn()` as its `HttpClientInterface`. Genuinely non-blocking for any API-based transport (Sendgrid, Mailgun, Postmark, SES, ...) it resolves to; `EsmtpTransport` (SMTP) ignores the injected client and opens a raw, genuinely blocking socket regardless — a disclosed exception, not a bug.
- No Kinetis-owned `MailerInterface` — `Symfony\Component\Mailer\MailerInterface` is used directly, the same "don't wrap an already-right abstraction" reasoning `kinetis/storage` already applies to `League\Flysystem\FilesystemOperator`.
- `Transport::fromDsn()` discovers whichever bridge package (`symfony/sendgrid-mailer`, `symfony/mailgun-mailer`, ...) is actually installed via its own `class_exists()`-gated factory list — `MailerFactory` has no dispatch logic of its own.
- Mail is queueable with zero code in this package: a `kinetis/queue` `Job`'s own `handle()` method constructor-injects `MailerInterface` exactly like any other service, resolved through the same container `QueueWorker`/`SyncQueue` already autowire against.
- Depends on `kinetis/framework` and `kinetis/revolt-http-client` (both via `path` repositories), `symfony/mailer`. Own `composer.json`/`phpunit.xml`/`phpstan.neon`.

## `packages/search-opensearch` (`kinetis/search-opensearch`)

Separate Composer package, not part of `kinetis/framework` core.

- `Kinetis\SearchOpenSearch\PackageBootstrap` — declared via `extra.kinetis`; with `SEARCH_OPENSEARCH_HOST` set, lazily binds `OpenSearch\Client` to `OpenSearchClientFactory::fromConfig()`'s result. Inert when the key is unset; the concrete client is the binding id because opensearch-php exposes no interface for it.
- `Kinetis\SearchOpenSearch\OpenSearchClientFactory::fromConfig(Config $config, string $connection = 'default'): OpenSearch\Client` — the only class in the package. Builds the client through `OpenSearch\TransportFactory::setHttpClient()` (a real PSR-18 injection point, part of the library's own non-deprecated construction path — the older `ClientBuilder`/`Transport`/`ConnectionPool` stack is deprecated since 2.4.0 and has no such injection point) with a `Symfony\Component\HttpClient\Psr18Client` wrapping `Kinetis\RevoltHttpClient\AmpHttpClientFactory::create()` as the client.
- No Kinetis-owned client interface — the real `OpenSearch\Client` is returned directly.
- `SEARCH_OPENSEARCH_HOST` is a single base URI; `SEARCH_OPENSEARCH_USERNAME`/`SEARCH_OPENSEARCH_PASSWORD` (Basic auth) and `SEARCH_OPENSEARCH_VERIFY_PEER` (default `true`) are optional, all via `Config::scopedKey()` for named connections.
- Depends on `kinetis/framework` and `kinetis/revolt-http-client` (both via `path` repositories), `opensearch-project/opensearch-php`, `symfony/http-client`. Own `composer.json`/`phpunit.xml`/`phpstan.neon`.

## `packages/telemetry` (`kinetis/telemetry`)

Separate Composer package, not part of `kinetis/framework` core.
Participates in `extra.kinetis`: a scan root covering
`Kinetis\Telemetry\Middleware\` (so `RequestSpanMiddleware` is
discovered as global middleware on install) and a `PackageBootstrap`.

- `Kinetis\Telemetry\PackageBootstrap` — binds
  `OpenTelemetry\API\Trace\TracerProviderInterface` on `AppScope`: the
  OTLP-exporting provider when `OTEL_EXPORTER_OTLP_ENDPOINT` is set, a
  `NoopTracerProvider` otherwise. Also replaces OTel's fiber-bound
  default context storage with the shared `ContextStorage` — Kinetis
  Fibers are scheduling units within one request, not independent
  execution contexts, and the swap is what lets a span begun by the
  middleware parent spans created inside `concurrently()` tasks.
  Registers the provider's `shutdown()` via
  `register_shutdown_function` — request end under boot-and-die,
  worker exit under a persistent runtime, so both shapes flush.
- `Kinetis\Telemetry\TracerFactory::fromConfig(Config): ?TracerProvider` —
  a `BatchSpanProcessor` over the OTLP/HTTP exporter, whose transport
  is `Symfony\Component\HttpClient\Psr18Client` wrapping
  `AmpHttpClientFactory::create()`, so span export suspends rather
  than blocks. `null` when no endpoint is configured.
- `Kinetis\Telemetry\Middleware\RequestSpanMiddleware` —
  `#[AsGlobalMiddleware(priority: 90)]`, a server span per request:
  method as the span name (route templates aren't visible to global
  middleware and raw paths would explode name cardinality), `url.path`,
  `http.response.status_code`, `php.memory.usage`, error status on 5xx
  or an exception, `traceparent` extraction for distributed traces. The
  span is active while the handler runs — the parent for everything
  below.
- `Kinetis\Telemetry\Persistence\TracingMysqlLink`/`TracingPostgresLink`
  (and the `TracingMysqlTransaction`/`TracingPostgresTransaction` their
  `beginTransaction()` hands back, plus the `TracingSqlLinkBase`/
  `TracingSqlTransactionBase` abstract bases) — a client span per
  `query()`/`execute()` named by the SQL's first keyword, `db.system.name`
  and `db.query.text` attributes, bound parameter values deliberately
  never recorded. `COMMIT`/`ROLLBACK` spanned too. Each decorator
  implements its dialect marker, so query-builder dialect detection is
  unaffected. Query spans are never activated — they read the current
  context as parent and end immediately, so concurrent queries can't
  interleave anyone's scope stack.
- `Kinetis\Telemetry\Queue\TracingQueue` — wraps any `QueueInterface`.
  `push()` gets a producer span; a consumer span opens at `pop()` and
  closes at `ack()`/`release()`/`fail()` (tracked via a
  `WeakMap<QueuedJob, ...>`), carrying `kinetis.job.class`/`attempt`/
  `outcome`, error status on `fail()`. Active while the job runs, so
  the job's own spans nest under it. Producer and consumer spans are
  separate traces — linking them needs context in the payload, which a
  decorator can't reach; a disclosed gap.
- `Kinetis\Telemetry\HttpClient\TracingHttpClient`/`TracingResponse` —
  a client span per outgoing request with `traceparent` injection
  (appended in Symfony's `"Name: value"` string form, coexisting with
  any existing header shape). The span ends when the response is
  consumed — `getContent()`/`toArray()`, an error, `cancel()`, or
  destruct as the safety net — never when `request()` returns, since
  requests through this transport complete later by design.
  `stream()` unwraps to the inner client's own responses (Symfony
  clients only stream responses they created), so stream consumers get
  destruct-time span timing.
- `Kinetis\Telemetry\Logging\TraceAwareLogger` — PSR-3 decorator adding
  `trace_id`/`span_id` to entry context when a span is recording;
  caller-supplied keys win.
- `Kinetis\Telemetry\Instrumentation\OtelTelemetry` — implements core's
  `Kinetis\Instrumentation\TelemetryInterface`, turning the framework's
  hooks into spans; `PackageBootstrap` swaps it into
  `Telemetry::global()` whenever the OTLP endpoint is configured. Which
  hooks *activate* their span (parenting whatever starts next) is the
  load-bearing choice: only strictly-nested single-fiber pairs do —
  middleware, controller, event/listener, the `concurrently()` batch,
  MCP tool calls, worker jobs. Query and per-task spans never activate:
  they can overlap across fibers on the shared context, and activating
  them would interleave the scope stack. `jobPushMetadata()` injects a
  `traceparent` carrier the backend stores with the job;
  `jobStarted()` extracts it, parenting the consumer span into the
  producer's trace — one trace across processes.
- Depends on `kinetis/framework`, `kinetis/revolt-http-client`,
  `open-telemetry/sdk`, `open-telemetry/exporter-otlp`,
  `symfony/http-client`, `nyholm/psr7`, `psr/log`;
  `kinetis/persistence`/`kinetis/queue` only in `require-dev` — the
  decorators' classes load lazily, so neither is forced on an install
  that only wants request spans. Own
  `composer.json`/`phpunit.xml`/`phpstan.neon`.

## `packages/auth` (`kinetis/auth`)

Separate Composer package, not part of `kinetis/framework` core.

- `Kinetis\Auth\BearerAuthMiddleware` — PSR-15 route middleware (never global) validating an `Authorization: Bearer <token>` header against an app-supplied `UserProviderInterface`, registering the resolved user on the current `RequestScope` as `CurrentUserInterface` on success, or returning `401` with a `WWW-Authenticate: Bearer` header on failure. Resolved fresh per request from the route's own `RequestScope`, so it constructor-injects `RequestScope` directly.
- `Kinetis\Auth\UserProviderInterface` — one method, `findByToken(string $token): ?CurrentUserInterface`. Storage-agnostic; the app implements it.
- `Kinetis\Auth\TokenGenerator` — `generate(int $bytes = 32): string`, a `random_bytes()` wrapper, hex-encoded.
- Depends on `kinetis/framework` (via a `path` repository to this monorepo's root), `nyholm/psr7` (`BearerAuthMiddleware`'s `401` response), `psr/http-message`, `psr/http-server-middleware`. Own `composer.json`/`phpunit.xml`/`phpstan.neon`.

## `packages/auth-jwt` (`kinetis/auth-jwt`)

Separate Composer package, not part of `kinetis/framework` core.

- `Kinetis\AuthJwt\JwtAuthMiddleware` — PSR-15 route middleware (never global) verifying an `Authorization: Bearer <token>` header's signature via `firebase/php-jwt`, registering the decoded claims as a `JwtUser` (`CurrentUserInterface`) on success, or returning `401` with `WWW-Authenticate: Bearer` on failure — a decode exception, a structurally valid token with no `sub` claim, and a revoked token (checked against the optional `RevocationStore`) are all treated identically. `$key` is the shared secret for `HS256`/`HS384`/`HS512`, the *public* half of a key pair (PEM string) for `RS256`/`RS384`/`RS512` — `JwtIssuer` takes the matching *private* half — or an `array<string, Firebase\JWT\Key>` map keyed by `kid`, for verifying against more than one key at once during a rotation; a plain string keeps working unchanged. Deliberately not `final`: `#[Middleware(...)]` carries only a class-string, with nowhere to pass a key, so a subclass supplying it via a constructor of only class-typed parameters is the documented pattern.
- `Kinetis\AuthJwt\JwtUser` — wraps the decoded claims (`stdClass`). `id()` reads `sub`, throwing if it's missing or non-scalar; `claim(string)`/`claims()` expose the rest.
- `Kinetis\AuthJwt\JwtIssuer` — `issue(string|int $subject, array $claims = [], ?int $ttlSeconds = 3600): string`, signing with the same key/algorithm `JwtAuthMiddleware` verifies against. `sub`/`iat`/`exp`/`jti` (a random unique token ID) always win over a same-named entry in `$claims`. An optional constructor `$kid` is written into the token's own header, for `JwtAuthMiddleware`'s multi-key `$key` map to select against.
- `Kinetis\AuthJwt\RevocationStore` — a `Psr\SimpleCache\CacheInterface`-backed denylist. Per-token: `revoke(string $jti, int $ttlSeconds)` is the primitive; `revokeToken(JwtUser $user)` derives the TTL from the token's own `exp` claim automatically. Per-user ("log out everywhere"): `revokeAllForUser(string|int $userId, int $ttlSeconds)` stores an inclusive `iat` cutoff; `isRevokedForUser()` compares a token's own `iat` against it. Requires a real cache — construction over `NullSimpleCache` throws `Exception\RevocationUnavailableException`, since a denylist that never stores anything would let every revoked token stay valid until natural expiry.
- `Kinetis\AuthJwt\RefreshTokenStore` — a `Psr\SimpleCache\CacheInterface`-backed, single-use opaque refresh token, independent of `RevocationStore`. `issue(string|int $subject, array $claims = [], int $ttlSeconds = 1_209_600): string` stores `sha256(token) => {subject, claims, issuedAt}`; `redeem(string $token): ?array` atomically reads and deletes the entry the moment it's looked up (valid or not, via `Kinetis\SimpleCache\AtomicConsumeInterface::consume()`) and returns `{subject, claims}` or `null`. `revoke(string $token): void` invalidates one token directly. `revokeAllForUser(string|int $userId, int $ttlSeconds): void` stores a per-subject cutoff timestamp (the same mechanism `RevocationStore::revokeAllForUser()` uses) rather than an enumerated list — `redeem()` checks a token's own `issuedAt` against its subject's latest cutoff, inclusive. Construction throws `Exception\RefreshTokenUnavailableException` over `NullSimpleCache` (same as `RevocationStore`) or over any cache not implementing `AtomicConsumeInterface` — a `get()` then a separate `delete()` would let two concurrent redeems of the same token both succeed.
- `Kinetis\AuthJwt\JwkSet::fromRsaPublicKeys(array $publicKeysByKid, string $algorithm = 'RS256'): array` — builds an RFC 7517 JWK Set (`{"keys": [...]}`) from one or more PEM-format RSA public keys via `openssl_pkey_get_public()`/`openssl_pkey_get_details()`, base64url-encoding the modulus/exponent. RSA only; throws `Exception\JwkSetException` for an invalid PEM or a non-RSA key.
- Depends on `kinetis/framework` (via a `path` repository to this monorepo's root), `firebase/php-jwt` (`^7.1` — `6.10`/`6.11` are excluded by an open security advisory), `psr/simple-cache` (`RevocationStore`/`RefreshTokenStore`), `ext-openssl` (`JwkSet`), `nyholm/psr7`, `psr/http-message`, `psr/http-server-middleware`. Own `composer.json`/`phpunit.xml`/`phpstan.neon`.

## `packages/session` (`kinetis/session`)

Separate Composer package, not part of `kinetis/framework` core.
Participates in `extra.kinetis` with a `PackageBootstrap` plus a scan
root covering `Kinetis\Session\Console` (the `session:gc` command);
both middlewares are explicit per-route opt-ins.

- `Kinetis\Session\SessionStoreInterface` — `read(id): ?array` / `write(id, data, lifetimeSeconds)` / `destroy(id)`. Payloads are JSON-serialized `array<string, mixed>`, never PHP `serialize()`; expiry is the store's own job; concurrency is declared last-write-wins — no locking, deliberately, since serializing a browser's parallel requests would fight the concurrent-worker model.
- `Kinetis\Session\GarbageCollectableStoreInterface` — `gc(): int`, deleting every expired session and returning the count. Implemented by the file and sql stores; the cache store leaves it out because its backend expires entries itself.
- `Kinetis\Session\Console\GcCommand` — the `session:gc` command on `vendor/bin/kinetis`. Calls `gc()` on the bound store and prints the count; for a store without `GarbageCollectableStoreInterface` it reports that the backend expires entries on its own and exits `0`; with no store bound at all it exits `1` naming `SESSION_DRIVER`. Nothing schedules it — cron or an equivalent does.
- `Kinetis\Session\Session` — what a controller constructor-injects (registered on the RequestScope by the middleware). `get`/`set`/`has`/`remove`/`all`, `flash()`/`flashed()` (survives exactly one following request, aged at commit), `csrfToken()` (generated on first use, stored in the session), `regenerate()` (fresh id, same data, old payload destroyed — the fixation defense), `destroy()`. Lazy: the store isn't read until first access, and `commit()` writes only when something changed — an untouched session costs no round trip and no cookie.
- `Kinetis\Session\Store\FileSessionStore` — one JSON file per session (`sess_{id}`), expiry embedded as a timestamp; an expired file is deleted when next read, and `gc()` sweeps the rest for `session:gc`. Validates ids against `^[a-f0-9]{32}$` before building any path — defense in depth against traversal even though the middleware validates first.
- `Kinetis\Session\Store\CacheSessionStore` — over PSR-16, the backend's TTL as expiry; rejects `NullSimpleCache` at construction (a store that never stores means logins that silently don't stick). Redis sessions are this store plus the `CacheInterface` binding `kinetis/cache-redis` already provides.
- `Kinetis\Session\Store\SqlSessionStore` — `kinetis_sessions` (id/payload/expires_at) over the generic `SqlLink` contract; dialect-agnostic SQL. The upsert is UPDATE-then-INSERT with a catch-and-re-UPDATE on a primary-key collision — surviving both MySQL's 0-affected-rows-on-identical-values report and racing first-writes. Expired rows stay until `gc()` (the `session:gc` command) deletes them. Migration stubs in `resources/migrations/`, never auto-created.
- `Kinetis\Session\Middleware\SessionMiddleware` — route middleware only (the `BearerAuthMiddleware` structural rule): reads the cookie (id validated, tampered values get a fresh session), registers a lazy `Session` on the scope, and afterwards commits + sets the cookie only when needed. Cookie: `HttpOnly` always, `Path=/`, no `Domain`, `Secure`/`SameSite`/name/lifetime from `SESSION_*` config. Validates the configured name at construction: it must be a legal cookie token, and a `__Host-`/`__Secure-` prefix (matched case-sensitively) requires `SESSION_SECURE` — a browser drops such a cookie silently, which presents as sessions that never persist. `SESSION_SAMESITE` is validated there too: `Strict`, `Lax`, or `None` matched case-insensitively and normalised to that casing in the header, with `None` requiring `SESSION_SECURE` for the same reason. Reads `cookieParams` first with the raw `Cookie` header as fallback, so `TestClient`-built requests work by setting the header.
- `Kinetis\Session\Middleware\CsrfMiddleware` — synchronizer-token check on non-GET/HEAD/OPTIONS, via `X-CSRF-Token` header or a form body's `_token`, `hash_equals()` comparison, `403` on mismatch; a missing upstream `SessionMiddleware` is a distinct `500` naming the declaration-order mistake. JSON bodies use the header — the dispatcher decodes JSON itself, so `getParsedBody()` never carries `_token` for them.
- `Kinetis\Session\PackageBootstrap` — with `SESSION_DRIVER` set, binds `SessionStoreInterface` as a lazy factory (resolved on first use, after `boot()` and every sibling bootstrap have run — which is what lets the `cache` driver consume the boot-time `CacheInterface` binding and the `sql` driver consume persistence's link binding regardless of bootstrap order). Unknown driver throws naming the valid set; `sql` without kinetis/persistence installed, or with no link bound, throws naming the fix.
- Depends on `kinetis/framework`, `psr/simple-cache`, `psr/http-message`, `psr/http-server-middleware`; `kinetis/persistence`/`kinetis/cache-redis` only in `require-dev` — store classes load lazily. Own `composer.json`/`phpunit.xml`/`phpstan.neon`.

## See also

- {doc}`appendix` — the same reference map for core (`kinetis/framework`).
- {doc}`appendix-ci` — what actually runs in CI, including the
  real-backend integration checks for several packages listed above.
- {doc}`appendix-contributing` — the monorepo layout, dev environment
  setup, and the manifest-driven tooling for changing a package's
  dependencies.
- {doc}`migrations`, {doc}`query-builder`, {doc}`queue`, {doc}`queue-sqs`,
  {doc}`queue-rabbitmq`, {doc}`storage`, {doc}`storage-s3`,
  {doc}`revolt-http-client`, {doc}`aws-sigv4`, {doc}`mailer`,
  {doc}`search-opensearch`, {doc}`auth`, {doc}`auth-jwt` — the
  task-oriented page for each package above.
