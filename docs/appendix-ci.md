# Appendix: Continuous Integration

This project's CI runs entirely on GitHub Actions — no separate CI
service to configure. A reference map of what runs on every push/PR
and what it checks, for the CI configuration itself, not the
framework's own code. Six workflow files under `.github/workflows/`
answer for a push to `main` or a pull request. Three run on every one of
them: `ci.yml`, `semgrep.yml` and `monorepo-validate.yml` (see
{doc}`appendix-contributing` for what it enforces). Three are
path-filtered on `packages/**` plus their own workflow file —
`integration.yml`, `infection.yml`, and `sonarqube.yml`, which also
watches `sonar-project.properties` — so a docs- or tooling-only change
gets no result from them at all, which is what
{doc}`appendix-contributing`'s release-gate section turns into a rule
about when a round can publish. `.github/dependabot.yml` sits alongside
them. Two more workflows exist but are out of this page's scope, since
neither runs on an ordinary push/PR: `deploy-docs.yml` (publishes
`docs/` to `kinetis.dev` on a push to `main`) and `release.yml` (gates
on the pushed commit's own results, then publishes each package's own
release repo — see {doc}`appendix-contributing`).

## `ci.yml` — static checks and unit tests, per package

One job per package — every package in `packages.manifest.json`, plus
`tools/` — matrixed across PHP 8.4 and 8.5 — every check below runs against both,
not just one — each running against the exact same Docker images used
for local development. Every push to `main` runs the whole matrix,
whatever the push touched: a release round gates on this workflow having
succeeded at the exact commit it publishes, and it can publish a version
whose own push came earlier, so a commit that skipped the matrix would
leave the gate proving nothing about the package code being tagged. Pull
requests are path-filtered instead, `packages.manifest.json` included —
a version bump changes the manifest and nothing else, since the
generated `composer.json` carries no version field:

- `composer validate --strict`
- `composer install`
- `composer audit` — checks every installed dependency against the
  FriendsOfPHP security advisory database.
- PHPUnit — every package's own existing, fake-backed unit test suite.
  `kinetis/persistence` runs its suite in a container that compiles
  `ext-sockets` first: its native Postgres driver refuses to construct
  without it, and the suite constructs one. Only that step needs the
  extension — PHPStan and Psalm read the package's own stubs.
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

Four paths are suppressed in `.semgrepignore`, with the reasoning
recorded there:

- `docs/_templates/page.html` — mirrors the Furo Sphinx theme's own
  footer template verbatim; the flagged template variables are
  Sphinx-internal, build-time navigation values, never live request
  input, since this renders to static HTML.
- `packages/auth-jwt/tests/Fixtures/RsaKeyPair.php` and
  `UndersizedRsaKeyPair.php` — synthetic RSA key pairs generated solely
  to test RS256 verification and the undersized-key refusal, not real
  credentials.
- `packages/revolt-http-client/tests/Fixtures/reflect-server.php` — a
  test-only `php -S` server whose whole purpose is echoing the request
  back as JSON, so the echoed-request rule's XSS concern has no HTML
  context to apply to.

`--error` gates the build on a real finding, failing the workflow rather
than only reporting it.

## `integration.yml` — real backends, not fakes

A fake settles what pure PHP decides — an argument rejected, an envelope
encoded, a settlement fenced against a scripted link — and every class
below carries a `ci.yml` suite for exactly that. It settles nothing
about the backend: whether `FOR UPDATE SKIP LOCKED` holds under
contention, whether a killed worker's lease comes back, whether a
`MOVED` redirect lands where the slot map says. That half runs here,
against the real thing.

They arrive in two shapes. A standalone PHP script under
`tests-integration/` is the linear form — push, pop, settle, assert —
and carries the queue, mailer, OpenSearch, LocalStack and migration
checks. An env-gated PHPUnit suite under `tests/Integration/` is the
other, for cases that want fixtures and per-test setup: `persistence`'s
drivers and TLS, `redis`/`cache-redis`'s cluster routing, `session`'s
store rules, `query-builder`'s cursor pagination. It skips itself when
the backend variables are unset, which is what keeps an ordinary
`ci.yml` run database-free, and the same files run again under coverage
in `sonarqube.yml`. `query-builder` uses both forms; the conformance
suites are the second. The entries below name what each job covers
rather than which form it took.

- **`query-builder`** (MySQL 8.4, MariaDB 11.4, Postgres 16) —
  `Query::get()`/`first()`/`count()`/`insertGetId()`/`update()`/
  `delete()`/`join()`/`paginate()`/`cursorPaginate()`, and the null
  predicate forms (`IS NULL`/`IS NOT NULL`).
- **`queue-redis`** (Redis 7) — `RedisQueue`: push/pop/ack/release/fail,
  attempts, priority queues, plus four dedicated scripts beside the main
  one — ten concurrent processes racing for one delayed job, two
  byte-identical payloads staying two jobs, a killed worker's lease
  reclaimed and redelivered, and delayed promotion staying inside its
  batch bound.
- **`queue-sql`** (MySQL 8.4, MariaDB 11.4) — `SqlQueue`: the same
  push/pop/ack/release/fail surface over `FOR UPDATE SKIP LOCKED`,
  reservation tokens, and priority ordering.
- **`queue-rabbitmq`** (RabbitMQ) — `RabbitMqQueue`: push/pop/ack/
  release/fail, `maxAttempts` round-tripping through message headers,
  priority cycling across two real queues, real delays through the delay
  ladder (a three-second delay pushed behind a ten-minute one coming due
  on its own wait, `size()`/`clear()` reaching both from a connection
  that never pushed them, and the delay ceiling rejected), and
  `release()` leaving a job unacked when the broker doesn't confirm the
  replacement. Its own dedicated job, never sharing a process with
  anything else.
- **`persistence-and-cache-redis`** (MySQL 8.4, MariaDB 11.4, Postgres 16,
  Redis 7) — `kinetis/persistence`'s `TransactionGuard`: commit/
  rollback/`rollbackDangling()`; `kinetis/cache-redis`'s
  `RedisSimpleCache`: the full PSR-16 surface, TTL expiry, and the
  conditional `replace()`; `kinetis/session`'s `SqlSessionStore` and
  `RedisSessionStore`: the terminal update rule, which rests on MySQL's
  changed-row count and Redis's `SET ... XX` refusal. None of these
  classes lives in core — see {doc}`persistence`, {doc}`session` and
  {doc}`appendix-packages`.
- **`mailer`** (Mailpit) — `MailerFactory`: a real SMTP send, read back
  through the mail server's own API.
- **`search-opensearch`** (two real OpenSearch containers, one with the
  security plugin disabled and one enabled with a self-signed
  certificate) — `OpenSearchClientFactory`: index/search/delete against
  the first, reached over `http` with `SEARCH_OPENSEARCH_PLAINTEXT=true`;
  an unauthenticated request rejected, a correctly Basic-authenticated
  request succeeding, and the default `SEARCH_OPENSEARCH_VERIFY_PEER=true`
  rejecting the self-signed certificate, against the second.
- **`migrations`** (MySQL 8.4, MariaDB 11.4, Postgres 16) —
  `MigrationRunner`/`SqlMigrationRepository`: migrate/status/rollback
  against a real fixture migration file.
- **`localstack`** (LocalStack: SQS + S3) — `SqsQueue`: push/pop/ack/
  release/fail, `maxAttempts`, priority queues; `S3FilesystemFactory`:
  write/read/exists/list/copy/move/delete/deleteDirectory over the
  plain-HTTP endpoint its opt-in covers.
- **`redis-cluster`** (`grokzen/redis-cluster`, 3 masters + 3 replicas) —
  `kinetis/redis`'s `ClusterClient`: keys routed to the master that owns
  them, `nodes()` against the live topology, and forced migrations
  exercising `-MOVED`, `-ASK`, an `ASK`-redirected script, and a
  `MOVED`-then-`ASK` sequence inside one operation. `kinetis/cache-redis`
  then covers the PSR-16 surface across shards and a `clear()` that scans
  every master while leaving other keys alone. Each case restores the
  slots it changes. The job runs the suite twice against one cluster to
  enforce that isolation.
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
  folding, client address, request identity, form/binary bodies (under
  the `enable_post_data_reading=0` the Kinetis SAPI adapters require, so
  the body each parses is the client's own), the `400` for a body it
  cannot parse and the `413` for one past a `Kinetis\Http\Form\FormLimits`
  ceiling, and — timed on the wire —
  incremental streaming, which is why the nginx fixture sets
  `fastcgi_buffering off`: with the default on, the stream arrives as
  one lump and the case fails, as verified.
- **`roadrunner-conformance`** — the same shared suite against
  `Kinetis\RoadRunnerAdapter\RoadRunnerAdapter`, structurally simpler
  than `runtime-conformance` above: `RoadRunnerDriver` spawns a real
  `rr serve` process (which spawns the PHP worker as its own child)
  directly from inside the test run, so this job needs no separate SAPI
  container or Docker network — one runner, `shivammathur/setup-php`
  providing a real, prebuilt `ext-sockets` (it compiles under Alpine
  too, just not worth doing here — see {doc}`runtime-adapters`), a real
  binary fetched via `spiral/roadrunner-cli`'s `vendor/bin/rr get-binary`,
  and the suite itself. The whole suite runs, unfiltered: the two
  behaviors this environment cannot deliver — a purely-numeric
  header name, dropped by an upstream bug, and cookie order, lost to a
  Go map — are declared by `RoadRunnerDriver` and asserted in both
  directions by the shared suite, so they are covered here rather than
  skipped. Both are disclosed in `RoadRunnerAdapter`'s own docblock and
  {doc}`runtime-adapters`.
- **`pingpong`** — not a package's own real-backend script like every
  job above; the real `docker compose up --build` stack (`app`, `mysql`,
  `redis`, `soketi`, `migrate`, `queue-worker`, `cron`) brought up from
  cold and exercised over real HTTP/SQL. `COMPOSE_FILE` puts this
  repo's `docker-compose.monorepo.yml` on top of the package's own
  standalone compose file, so the stack runs against the sibling
  checkouts; every step is otherwise the command it would be against the
  released package. `GET /` (200), a real
  `POST /pong/direct` request checked against the resulting database row
  going straight to `ponged`, a real `POST /pong/queued` request checked
  as `pending` immediately and polled until the separate `queue-worker`
  container ponged it, and the `cron` container's own row (created and
  ponged with no HTTP request involved at all) polled the same way.

`query-builder`, `queue-sql`, `persistence-and-cache-redis`, and
`migrations` each run twice — once against MySQL, once against
MariaDB — via a matrix over the database image, not separate jobs or
duplicated scripts. Only the service container's image and health-check
command (`mysqladmin` vs. `mariadb-admin`) differ between the two matrix
entries.

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

One matrix job per package carrying its own `infection.json5`.
`kinetis/pingpong` has none — it is a demo application, read as example
code rather than called as an API — and `tools/` is the monorepo's own
tooling rather than a published package. Each job runs
`composer install`, then Infection with PCOV as the coverage driver,
gated on `--min-msi`/`--min-covered-msi` — a real, non-zero threshold per
package, set with a margin below that package's own measured score.
`kinetis/persistence` compiles `ext-sockets` into that container first,
for the same requirement its `ci.yml` suite carries above. Runs on PHP
8.4 only, not matrixed across 8.4/8.5 like `ci.yml`/`integration.yml`.

On a pull request, a package whose own `src/` is unchanged relative to
the base branch skips its job, and every package the PR does change is
mutated in full. The thresholds are measured against a whole package
and are only meaningful against a whole package's mutant set, so a
changed-lines subset is never scored against them. Every push to `main`
runs the same full suite.

Thresholds, as declared in `infection.yml` — which is the authority; a
package's current score is whatever its own job last reported, and sits
above the number here by design:

| Package | min-msi / min-covered-msi |
|---|---|
| `auth` | 70% |
| `auth-jwt` | 60% |
| `authorization` | 90% |
| `aws-sigv4` | 90% |
| `bref-adapter` | 70% |
| `broadcasting` | 75% |
| `cache-redis` | 75% |
| `core` | 75% |
| `mailer` | 90% |
| `mcp` | 75% |
| `mcp-docs` | 60% |
| `migrations` | 75% |
| `persistence` | 75% |
| `query-builder` | 80% |
| `queue` | 60% |
| `queue-rabbitmq` | 50% |
| `queue-redis` | 55% |
| `queue-sql` | 50% |
| `queue-sqs` | 55% |
| `redis` | 60% |
| `revolt-http-client` | 75% |
| `roadrunner-adapter` | 85% |
| `search-opensearch` | 70% |
| `session` | 65% |
| `skeleton` | 90% |
| `storage` | 90% |
| `storage-s3` | 15% |
| `telemetry` | 70% |

`queue-sqs` and `storage-s3` carry the lowest floors. Neither
`infection.json5` excludes anything, so both mutate all of `src` and
each floor is what that whole set measured.

## `sonarqube.yml` — SonarQube Cloud

A repo-wide static-analysis and coverage pass via the official
`SonarSource/sonarqube-scan-action`, configured by
`sonar-project.properties` at the repo root (source paths spanning
core's `src/` plus every satellite package's own `src/`).

Runs PHPUnit with PCOV coverage for core and every satellite package
with a PHPUnit suite, feeding the resulting Clover reports into the
scan via `sonar.php.coverage.reportPaths` — on PHP 8.4 only, not
matrixed across 8.4/8.5 like `ci.yml`/`integration.yml`. This job brings
up MySQL, Postgres, a single Redis and a Redis Cluster of its own, for
one reason: `kinetis/persistence`'s and `kinetis/cache-redis`'s
env-gated `tests/Integration` suites have to run *under PCOV here* for
the driver and cluster code they exercise to count as measured coverage.
Without them those tests skip and that code reads as uncovered.

`sonar.coverage.exclusions` names the classes whose behavior a real
backend decides: `RedisQueue`, `SqlQueue`, `SqsQueue` and its
`SqsQueueException`, `RabbitMqQueue`, `RedisSessionStore`, plus the thin
wiring around them — `kinetis/queue`'s and `kinetis/persistence`'s
`PackageBootstrap`, `queue:work`, and `kinetis/migrations`' console
commands. Each still carries its own PHPUnit suite for the part pure PHP
can decide; what the exclusion keeps out of the metric is the rest,
which `integration.yml` proves against a live container and no
line-coverage number here can speak for.

One pair of files with known, structural duplication — the mysqli and
pgsql drivers, whose pooling logic isn't trait-compatible without
widening production connection-handling signatures — is excluded from
duplication detection via `sonar.cpd.exclusions`, with the reasoning
recorded inline in `sonar-project.properties` itself.

Requires a `SONAR_TOKEN` repository secret from the project's own
SonarCloud dashboard; analysis method is "With GitHub Actions."

## `dependabot.yml`

One weekly `composer` entry per package directory, one for `tools/`, and
one `github-actions` entry for the workflow files themselves. A
dependency bump opens a real pull request, which re-runs every workflow
above against it before a human ever looks at it.

`validate-manifest.php`'s `workflow-coverage` check reads `ci.yml`,
`infection.yml` and the SonarQube pair, not this file, so a new package
without an entry here is watched by nothing and fails no check. Adding
the entry belongs in the same change as the package.

## See also

- {doc}`appendix` — the same reference map for core, by namespace.
- {doc}`appendix-packages` — the same reference map for every satellite
  package, by namespace.
- {doc}`appendix-contributing` — how to run these checks locally before
  pushing, and how `monorepo-validate.yml`'s enforcement actually works.
- {doc}`testing` — `TestClient`, for exercising a `Kernel` end-to-end in
  a consumer's own test suite.
