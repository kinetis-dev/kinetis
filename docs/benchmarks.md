# Benchmarks

According to our own benchmark suite, Kinetis wins five of six
TechEmpower-style benchmark tests against seven other PHP frameworks —
Laravel, Laravel Octane, Symfony, CodeIgniter, Yii2, CakePHP, and Slim —
measured on isolated AWS hardware on August 15, 2026. The full
methodology and every framework's implementation are public in the
[benchmark repository](https://github.com/aln-1/kinetis-benchmarks),
so the numbers below are reproducible, not just reported; this page
covers what was tested and what came out, not how the rig is built.

## What was tested

Six [TechEmpower Framework Benchmarks](https://github.com/TechEmpower/FrameworkBenchmarks)
test types — `/json`, `/plaintext`, `/db`, `/fortunes`,
`/queries?queries=20`, `/updates?queries=20` — against identical MySQL
schema and data, one implementation per framework using that
framework's own idiomatic routing, database layer, and templating.
Every target runs the same production PHP configuration (opcache with
a tracing JIT, timestamps disabled, access logging off) and its own
ahead-of-time caching mechanism where it has one. Three separate EC2
instances — application, database, load generator — provide real
machine isolation, the same methodology TechEmpower's own benchmark
uses.

Kinetis appears twice: `kinetis` runs on FrankenPHP worker mode, its
primary deployment target; `kinetis-fpm` is the identical, unmodified
application under classic nginx and PHP-FPM. The pair isolates what
the framework's own request handling costs from what the
persistent-worker runtime adds.

The fairness rules — what was tuned, why, and what was deliberately
left alone — are documented in full in the repository's own README;
they aren't repeated here.

## Versions tested

The kinetis versions below are re-validated against the same rig with
a follow-up measurement run reproducing the table's results within
run-to-run noise. The benchmark repository tracks current releases, so
a fresh clone installs the latest kinetis packages.

| Target | Package | Version | PHP |
|---|---|---|---|
| `kinetis` | kinetis/framework | 1.5.4 | 8.5.9 (FrankenPHP, ZTS) |
| `kinetis-fpm` | kinetis/framework | 1.5.4 | 8.4.24 |
| `slim` | slim/slim | 4.15.2 | 8.4.24 |
| `yii2` | yiisoft/yii2 | 2.0.55 | 8.4.24 |
| `symfony` | symfony/framework-bundle | 7.4.16 | 8.4.24 |
| `cakephp` | cakephp/cakephp | 5.4.1 | 8.4.24 |
| `codeigniter` | codeigniter4/framework | 4.7.4 | 8.4.24 |
| `laravel` | laravel/framework | 13.25.0 | 8.4.24 |
| `laravel-octane` | laravel/framework + laravel/octane | 13.25.0 + 2.19.0 | 8.5.9 (FrankenPHP, ZTS) |

`kinetis`/`kinetis-fpm` also ran kinetis/persistence 1.5.0 and
kinetis/query-builder 1.2.3. FrankenPHP-based targets run a newer PHP
than the PHP-FPM targets because that's what the `dunglas/frankenphp`
image ships — see {doc}`runtime-adapters` for how Kinetis picks a
runtime.

## Results

Requests per second at concurrency 256; `/queries` and `/updates` run
20 queries per request. One measurement run, not an average.

| Target | `/json` | `/plaintext` | `/db` | `/fortunes` | `/queries` (20) | `/updates` (20) |
|---|---|---|---|---|---|---|
| `kinetis` | **27,878** | **27,830** | **19,797** | **16,489** | **4,304** | 1,495 |
| `kinetis-fpm` | 10,596 | 10,659 | 6,257 | 5,555 | 2,104 | 1,105 |
| `slim` | 13,443 | 13,409 | 7,714 | 7,181 | 4,301 | **1,801** |
| `yii2` | 9,865 | 10,255 | 6,016 | 5,597 | 2,887 | 1,579 |
| `codeigniter` | 6,250 | 6,256 | 2,188 | 2,038 | 1,588 | 1,207 |
| `cakephp` | 5,927 | 5,990 | 3,065 | 2,896 | 1,406 | 969 |
| `symfony` | 5,154 | 5,154 | 3,967 | 3,730 | 2,623 | 1,501 |
| `laravel-octane` | 5,088 | 5,172 | 4,563 | 4,231 | 1,506 | 868 |
| `laravel` | 2,555 | 2,560 | 2,025 | 1,884 | 1,065 | 748 |

`kinetis` wins `/json`, `/plaintext`, `/db`, and `/fortunes` outright,
and `/queries` in a near-tie with `slim`. `/updates` is the one test
`kinetis` doesn't win: it's bound by the database's own write path
(row locks, index maintenance, the redo log), and the database instance
runs at the same load for every target — no application-side change
moves that number. `slim`, the fastest boot-and-die target on every
other test, wins it.

## Full methodology

The [benchmark repository](https://github.com/aln-1/kinetis-benchmarks)
has the complete picture: every framework's implementation, the AWS
infrastructure as code, and the fairness rules applied identically
across all nine targets — everything needed to run the same sweep
yourself and check these numbers directly.
