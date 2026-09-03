# Appendix: Continuous Integration

This project's CI runs entirely on GitHub Actions — no separate CI
service to configure. A reference map of what runs on every push/PR
and what it checks, for the CI configuration itself, not the
framework's own code. Six workflow files run on every push/PR,
`.github/workflows/`: `ci.yml`, `semgrep.yml`, `integration.yml`,
`infection.yml`, `sonarqube.yml`, `monorepo-validate.yml` (see
{doc}`appendix-contributing` for what it enforces), plus
`.github/dependabot.yml`. Two more workflows exist but are out of this
page's scope, since neither runs on an ordinary push/PR:
`deploy-docs.yml` (publishes `docs/` to `kinetis.dev` on a push to
`main`) and `release.yml` (splits and tags each package's own release
repo on a push to `main` — see {doc}`appendix-contributing`).

## `ci.yml` — static checks and unit tests, per package

One job per package — every package in `packages.manifest.json`, plus
`tools/` — matrixed across PHP 8.4 and 8.5 — every check below runs against both,
not just one — each running against the exact same Docker images used
for local development:

- `composer validate --strict`
- `composer install`
- `composer audit` — checks every installed dependency against the
  FriendsOfPHP security advisory database.
- PHPUnit — every package's own existing, fake-backed unit test suite.
  Skipped for `kinetis/pingpong`, which has none by design.
- PHPStan, level 8.
- Psalm, `--taint-analysis` — data-flow analysis for injection-style
  bugs (SQL injection, XSS, ...), a different lens than PHPStan's
  type-correctness checking. Results upload to GitHub's code-scanning
  tab as SARIF.

A separate job builds the Sphinx documentation with `-W` (warnings fail
the build), so a broken docs page can't merge silently.

## `semgrep.yml` — pattern-based security scanning

`p/php`, `p/security-audit`, and `p/secrets` rulesets, scanning the
whole repository once (not per package — Semgrep doesn't need to know
about Composer package boundaries). Same SARIF upload as Psalm's.

Two findings are suppressed in `.semgrepignore`, with the reasoning
recorded there:

- `docs/_templates/page.html` — mirrors the Furo Sphinx theme's own
  footer template verbatim; the flagged template variables are
  Sphinx-internal, build-time navigation values, never live request
  input, since this renders to static HTML.
- `packages/auth-jwt/tests/JwtAuthMiddlewareTest.php` — a synthetic RSA
  key pair generated solely to test RS256 JWT verification, not a real
  credential.

`--error` gates the build on a real finding, failing the workflow rather
than only reporting it.

## `integration.yml` — real backends, not fakes

Several classes across this project are tested only against real
service containers, not mocks — a mocked "was this method called with
X" test can't prove backend-specific correctness (a reliable queue's
ack/release mechanics, `FOR UPDATE SKIP LOCKED`, priority-queue
fallthrough). Each is a standalone PHP script (`tests-integration/run.php`,
or a descriptively-named file at the relevant package root), not a
disguised PHPUnit test.

- **`query-builder`** (MySQL 8.4, MariaDB 11.4, Postgres 16) —
  `Query::get()`/`first()`/`count()`/`insertGetId()`/`update()`/
  `delete()`/`join()`/`paginate()`/`cursorPaginate()`.
- **`queue`** (Redis 7, MySQL 8.4, MariaDB 11.4) — `RedisQueue`/
  `SqlQueue`: push/pop/ack/release/fail, attempts, priority queues.
- **`queue-rabbitmq`** (RabbitMQ) — `RabbitMqQueue`: push/pop/ack/
  release/fail, `maxAttempts` round-tripping through message headers,
  priority cycling across two real queues, and a real (not just
  configured) delay via the dead-letter-exchange mechanism. Its own
  dedicated job, never sharing a process with anything else — the one
  precondition `RabbitMqQueue`'s own disclosed `concurrently()`
  limitation actually needs.
- **`persistence-and-cache-redis`** (MySQL 8.4, MariaDB 11.4, Postgres 16,
  Redis 7) — `kinetis/persistence`'s `TransactionGuard`: commit/
  rollback/`rollbackDangling()`; `kinetis/cache-redis`'s
  `RedisSimpleCache`: the full PSR-16 surface, TTL expiry. Neither class
  lives in core — see {doc}`persistence` and {doc}`appendix-packages`.
- **`mailer`** (Mailpit) — `MailerFactory`: a real SMTP send, read back
  through the mail server's own API.
- **`search-opensearch`** (two real OpenSearch containers, one with the
  security plugin disabled and one enabled with a self-signed
  certificate) — `OpenSearchClientFactory`: index/search/delete against
  the first; an unauthenticated request rejected, a correctly
  Basic-authenticated request succeeding, and the default
  `SEARCH_OPENSEARCH_VERIFY_PEER=true` rejecting the self-signed
  certificate, against the second.
- **`migrations`** (MySQL 8.4, MariaDB 11.4, Postgres 16) —
  `MigrationRunner`/`SqlMigrationRepository`: migrate/status/rollback
  against a real fixture migration file.
- **`localstack`** (LocalStack: SQS + S3) — `SqsQueue`: push/pop/ack/
  release/fail, `maxAttempts`, priority queues; `S3FilesystemFactory`:
  write/read/exists/list/delete.
- **`redis-cluster`** (`grokzen/redis-cluster`, 3 masters + 3 replicas) —
  `ClusteredRedisSimpleCache`/`ClusterTopology`: the full PSR-16 surface
  routed across every node, `clear()` fanning out to every shard, and a
  real forced slot reassignment producing a genuine `-MOVED` reply that
  `guard()` must catch, refresh its topology from, and retry — not a
  simulated redirect.
- **`runtime-conformance`** (matrix: a `dunglas/frankenphp` worker behind
  Caddy; `php:8.4-fpm-alpine` behind `nginx:alpine`) — the shared runtime
  adapter conformance suite (`Kinetis\Testing\Runtime`, see
  {doc}`testing`) against each real SAPI, where the committed framework
  suite can only spawn `php -S`. The same shape as the local
  verification: the SAPI serving the conformance fixture (one FrankenPHP
  container; nginx plus a PHP-FPM container for the FPM leg), and a
  `php:8.4-cli-alpine` runner on the same Docker network executing
  `RemoteSuperglobalsConformanceTest` against it, every container
  mounting the checkout at `/app` so the fixture's state directory is
  one path on every side. Readiness is the fixture's own
  `/__conformance/ready` answering 204 through the full adapter path,
  not a TCP accept — nginx listens before the FPM pool behind it does. Exercises each SAPI's own superglobal population, header
  folding, client address, form/binary bodies, the `post_max_size` 400,
  and — timed on the wire — incremental streaming, which is why the
  nginx fixture sets `fastcgi_buffering off`: with the default on, the
  stream arrives as one lump and the case fails, as verified.
- **`roadrunner-conformance`** — the same shared suite against
  `Kinetis\RoadRunnerAdapter\RoadRunnerAdapter`, structurally simpler
  than `runtime-conformance` above: `RoadRunnerDriver` spawns a real
  `rr serve` process (which spawns the PHP worker as its own child)
  directly from inside the test run, so this job needs no separate SAPI
  container or Docker network — one runner, `shivammathur/setup-php`
  providing a real, prebuilt `ext-sockets` (it compiles under Alpine
  too, just not worth doing here — see {doc}`runtime-adapters`), a real
  binary fetched via `spiral/roadrunner-cli`'s `vendor/bin/rr get-binary`,
  and the suite itself. Two tests are excluded from this job's gate by
  name — a purely-numeric header name (a deterministic upstream bug)
  and cookie order (an occasional, probabilistic reordering) — both
  disclosed in `RoadRunnerAdapter`'s own docblock and {doc}`runtime-adapters`,
  neither reachable from this adapter's own code; the full, unfiltered
  suite still shows both, honestly, for anyone running it directly.
- **`pingpong`** — not a package's own real-backend script like every
  job above; the real `docker compose up --build` stack (`app`, `mysql`,
  `redis`, `soketi`, `migrate`, `queue-worker`, `cron`) brought up from
  cold and exercised over real HTTP/SQL: `GET /` (200), a real
  `POST /pong/direct` request checked against the resulting database row
  going straight to `ponged`, a real `POST /pong/queued` request checked
  as `pending` immediately and polled until the separate `queue-worker`
  container ponged it, and the `cron` container's own row (created and
  ponged with no HTTP request involved at all) polled the same way.

`query-builder`, `queue`, `persistence-and-cache-redis`, and `migrations`
each run twice — once against MySQL, once against MariaDB — via a matrix
over the database image, not separate jobs or duplicated scripts. Only
the service
container's image and health-check command (`mysqladmin` vs.
`mariadb-admin`) differ between the two matrix entries.

Every job above except `pingpong`, `runtime-conformance`, and
`roadrunner-conformance` (each exercises a real multi-container stack,
or in `roadrunner-conformance`'s case a fixed PHP version chosen to
match the `ext-sockets` requirement — not a bare per-package PHP
matrix) also runs across PHP 8.4 and 8.5, the same matrix `ci.yml`
uses — real-backend correctness is checked against both, not just one.

`redis-cluster` is the one job that runs the job itself inside a
`container:` rather than on the bare runner — a real multi-node Redis
Cluster advertises each node's own container-internal address for both
inter-node gossip and client MOVED-redirects, and only a job attached to
the same Docker network as the service container can reach that address
directly. PHP/Composer run directly inside the step for this job, rather
than through a nested `docker run` the way every other job here invokes
them, since a job container has no Docker-in-Docker socket available by
default.

## `infection.yml` — mutation testing

Mutates source code (flipping a comparison, removing a statement,
incrementing a constant, ...) and re-runs the covering tests per
mutant — a mutant the suite doesn't catch ("escaped") is a gap in
assertion rigor, not just a coverage gap.

One matrix job per package that has a PHPUnit suite. `kinetis/pingpong`
is excluded (no tests, nothing to mutate against), and `tools/` is the
monorepo's own tooling rather than a published package. Each job runs
`composer install`, then Infection with PCOV as the coverage driver,
gated on `--min-msi`/`--min-covered-msi` — a real, non-zero threshold per
package, set with a margin below that package's own measured score. Runs
on PHP 8.4 only, not matrixed across 8.4/8.5 like
`ci.yml`/`integration.yml`.

On a pull request, only the code the PR actually changes is mutated
(`--git-diff-filter` against the base branch), under the same
thresholds; a package the PR never touches skips its job outright.
Every push to `main` runs the full mutation suite, so the complete
score stays enforced there.

Thresholds, as declared in `infection.yml` — which is the authority; a
package's current score is whatever its own job last reported, and sits
above the number here by design:

| Package | min-msi / min-covered-msi |
|---|---|
| `auth` | 70% |
| `auth-jwt` | 70% |
| `aws-sigv4` | 90% |
| `bref-adapter` | 70% |
| `cache-redis` | 75% |
| `core` | 75% |
| `mailer` | 90% |
| `migrations` | 75% |
| `persistence` | 85% |
| `query-builder` | 80% |
| `queue` | 60% |
| `queue-rabbitmq` | 90% |
| `queue-sqs` | 55% |
| `revolt-http-client` | 75% |
| `roadrunner-adapter` | 80% |
| `search-opensearch` | 70% |
| `session` | 65% |
| `skeleton` | 90% |
| `storage` | 90% |
| `storage-s3` | 15% |
| `telemetry` | 70% |

`queue-sqs` and `storage-s3` sit lowest because their real
backend-specific logic (`SqsQueue`, the AWS S3 adapter path) is
real-backend-verified only, with no PHPUnit coverage (see
{doc}`appendix-packages`). Infection scores only the config-parsing
factory classes those two packages do unit-test, so the number reflects
established scope rather than a gap to close.

## `sonarqube.yml` — SonarQube Cloud

A repo-wide static-analysis and coverage pass via the official
`SonarSource/sonarqube-scan-action`, configured by
`sonar-project.properties` at the repo root (source paths spanning
core's `src/` plus every satellite package's own `src/`).

Runs PHPUnit with PCOV coverage for core and every satellite package
with a PHPUnit suite, feeding the resulting Clover reports into the
scan via `sonar.php.coverage.reportPaths` — on PHP 8.4 only, not
matrixed across 8.4/8.5 like `ci.yml`/`integration.yml`. `RedisQueue`,
`SqlQueue`, `SqsQueue` (and its `SqsQueueException`), and `RabbitMqQueue`
are excluded from the coverage calculation via `sonar.coverage.exclusions`,
matching their real-backend-only testing in `integration.yml`. Two pairs
of files with known, structural duplication — genuinely independent
satellite packages sharing the same third-party integration, and two
async DB drivers whose pooling logic isn't trait-compatible without
widening production connection-handling signatures — are excluded from
duplication detection via `sonar.cpd.exclusions`, with the reasoning
recorded inline in `sonar-project.properties` itself.

Requires a `SONAR_TOKEN` repository secret from the project's own
SonarCloud dashboard; analysis method is "With GitHub Actions."

## `dependabot.yml`

One `composer` ecosystem entry per package directory, plus one
`github-actions` entry for the workflow files themselves. A dependency
bump opens a real pull request, which re-runs every workflow above
against it before a human ever looks at it.

## See also

- {doc}`appendix` — the same reference map for core, by namespace.
- {doc}`appendix-packages` — the same reference map for every satellite
  package, by namespace.
- {doc}`appendix-contributing` — how to run these checks locally before
  pushing, and how `monorepo-validate.yml`'s enforcement actually works.
- {doc}`testing` — `TestClient`, for exercising a `Kernel` end-to-end in
  a consumer's own test suite.
