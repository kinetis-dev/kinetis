# Appendix: Continuous Integration

This project's CI runs entirely on GitHub Actions — no separate CI
service to configure. A reference map of what runs on every push/PR
and what it checks, for the CI configuration itself, not the
framework's own code. Five workflow files, `.github/workflows/`:
`ci.yml`, `semgrep.yml`, `integration.yml`, `infection.yml`,
`sonarqube.yml`, plus `.github/dependabot.yml`.

## `ci.yml` — static checks and unit tests, per package

One job per package (core plus every satellite package, 14 in total),
each running against the exact same Docker images used for local
development:

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

One matrix job per package, core plus every satellite package except
`kinetis/pingpong` (no PHPUnit tests, nothing to mutate against). Each:
`composer install`, then Infection with PCOV as the coverage driver,
gated on `--min-msi`/`--min-covered-msi`, a real, non-zero threshold per
package, set with a margin below each package's own score:

| Package | min-msi / min-covered-msi | Score at the time it was set |
|---|---|---|
| core | 75% | 81% |
| auth | 70% | 75% |
| auth-jwt | 70% | 77% |
| bref-adapter | 70% | 80% |
| mailer | 90% | 100% |
| migrations | 75% | 82% |
| query-builder | 80% | 86% |
| queue | 60% | 70% |
| queue-rabbitmq | 90% | 100% |
| queue-sqs | 55% | 66% |
| revolt-http-client | 70% | 80% |
| search-opensearch | 70% | 77% |
| skeleton | 90% | 100% |
| storage | 90% | 100% |
| storage-s3 | 15% | 20% |

`queue-sqs`/`storage-s3` score lowest: their real backend-specific logic
(`SqsQueue`, the AWS S3 adapter path) is real-backend-verified only,
with no PHPUnit coverage (see {doc}`appendix-packages`). Infection only
scores the config-parsing factory classes those two packages do
unit-test.

## `sonarqube.yml` — SonarQube Cloud

A repo-wide static-analysis and coverage pass via the official
`SonarSource/sonarqube-scan-action`, configured by
`sonar-project.properties` at the repo root (source paths spanning
core's `src/` plus every satellite package's own `src/`).

Runs PHPUnit with PCOV coverage for core and every satellite package
with a PHPUnit suite, feeding the resulting Clover reports into the
scan via `sonar.php.coverage.reportPaths`. `RedisQueue`, `SqlQueue`,
`SqsQueue` (and its `SqsQueueException`), and `RabbitMqQueue` are
excluded from the coverage calculation via `sonar.coverage.exclusions`,
matching their real-backend-only testing in `integration.yml`.

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
  pushing, and how the manifest-driven tooling `monorepo-validate.yml`
  enforces actually works.
- {doc}`testing` — `TestClient`, for exercising a `Kernel` end-to-end in
  a consumer's own test suite.
