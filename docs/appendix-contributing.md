# Appendix: Contributing to Kinetis

How the `kinetis-dev/kinetis` monorepo is actually structured, how to set
up a development environment, how to test a change, how the release
tooling works, and how branching/PRs/CI fit together. A practical
walkthrough, not a policy document — every command here is real and
runnable, the same Docker-only toolchain this project's own development
already uses throughout.

## The monorepo, in one paragraph

This repository is not a single Composer package — it's a monorepo
hosting the framework core and every satellite package as independent,
separately-installable Composer packages, each with its own
`composer.json`, test suite, and static-analysis config. `packages/framework/`
is the framework itself (`kinetis/framework`); everything else under
`packages/` — `auth`, `queue`, `query-builder`, `storage`, and so on — is
optional, depends on `kinetis/framework` (and sometimes on each other) via
a Composer **path repository** pointing at a sibling directory, and can be
worked on and tested in isolation. `docs/` (this site) and `.github/`
(CI/CD) are the only things that genuinely belong to the *monorepo* rather
than to one package.

```{code-block} text
:caption: Top-level layout

kinetis/
├── packages/
│   ├── framework/          # kinetis/framework — the core
│   ├── persistence/        # kinetis/persistence
│   ├── cache-redis/        # kinetis/cache-redis
│   ├── auth/                # kinetis/auth
│   ├── queue/                # kinetis/queue
│   └── ...                   # every other satellite package
├── docs/                     # this documentation site (Sphinx/MyST)
├── tools/                    # the manifest-driven release tooling — see below
├── packages.manifest.json    # canonical source of truth for every composer.json
└── .github/workflows/        # CI
```

Path-repo isolation is a deliberate choice, not an accident of history: a
satellite package's `composer.json` declares every sibling it actually
depends on, and `composer install` inside that one package's directory
resolves and tests against exactly that declared set — the same
correctness guarantee a real, separately-installed consumer would get.
An undeclared cross-package dependency fails to install *in this repo*,
not silently, only after someone installs the split package alone later.

```{warning}
Composer path repositories are **not transitive**. If package `C`
depends on `B`, and `B` depends on `A`, `C`'s own `composer.json` must
still declare `A` directly (with its own `path` entry) — Composer won't
walk `B`'s `repositories` array to find it. Every package in this repo
already does this correctly; keep it in mind if you're adding a new
cross-package dependency by hand rather than through the manifest
tooling below.
```

## Setting up a development environment

Nothing to install beyond Docker. This project deliberately never runs
`php` or `composer` directly on the host — every command in this guide,
and every command CI runs, goes through
`docker run --rm -v "$PWD":/app -w /app/<dir> <image> <command>`, so your
local results match CI exactly regardless of what PHP version happens to
be on your machine (or whether one is installed at all).

Clone the repo, then install one package's dependencies to confirm the
toolchain works:

```sh
git clone https://github.com/kinetis-dev/kinetis.git
cd kinetis

docker run --rm -v "$PWD":/app -w /app/packages/framework composer:2 install
docker run --rm -v "$PWD":/app -w /app/packages/framework php:8.4-cli-alpine php vendor/bin/phpunit
```

That's the whole setup. Each package's `vendor/` is gitignored and
independent — installing one package doesn't touch any other, and
there's no repo-wide `composer install` to run (there's no repo-wide
`composer.json` to run it against).

If you're working on a satellite package, install it the same way, from
*its own* directory:

```sh
docker run --rm -v "$PWD":/app -w /app/packages/queue composer:2 install
```

Composer resolves `kinetis/framework` (and any other sibling `queue`
declares) via the `path` repository entries already in
`packages/queue/composer.json` — pointing back at `../framework`,
`../persistence`, etc. — so this works immediately with no extra setup,
as long as those sibling directories exist in your checkout (they always
do; every package lives in the same monorepo).

```{note}
Docker occasionally hits transient network flakiness talking to
`repo.packagist.org`/`api.github.com` mid-resolve (a `curl error 7`, or
a timeout) — a real environment hiccup, not a project bug. Just re-run
the same `composer install`/`update` command; it isn't stateful in a
way that a retry could corrupt.
```

## Running the tests

Every package's test suite runs the identical way, from that package's
own directory:

```sh
# Unit tests (PHPUnit)
docker run --rm -v "$PWD":/app -w /app/packages/<name> php:8.4-cli-alpine php vendor/bin/phpunit

# Static analysis (PHPStan, level 8 everywhere)
docker run --rm -v "$PWD":/app -w /app/packages/<name> php:8.4-cli-alpine php vendor/bin/phpstan analyse --no-progress --memory-limit=512M

# Data-flow / taint analysis (Psalm)
docker run --rm -v "$PWD":/app -w /app/packages/<name> php:8.4-cli-alpine php vendor/bin/psalm --taint-analysis --no-progress
```

`kinetis/pingpong` has no PHPUnit suite by design (see {doc}`appendix-packages`)
— it's a runnable demo application, verified by actually running the
Docker Compose stack, not a library with its own unit tests. Every other
package, core included, has one.

Some packages' correctness genuinely can't be proven against a mock —
`RedisQueue`, `SqlQueue`, `SqsQueue`, and `RabbitMqQueue` (all in
`kinetis/queue`/`kinetis/queue-sqs`/`kinetis/queue-rabbitmq`) have **no
committed PHPUnit test at all**, deliberately: a mocked "was this method
called with X" assertion can't prove a reliable queue's ack/release
semantics or a real `FOR UPDATE SKIP LOCKED` race actually holds. These
are verified only against real backend containers, as standalone PHP
scripts under each package's `tests-integration/` — see {doc}`appendix-ci`
for the full list and what each one checks. You don't need to run these
locally for an ordinary change; CI runs them against real service
containers on every push.

```{tip}
`tools/` — the release tooling itself — has its own real, committed
PHPUnit suite too (38 tests). Run it the same way, with one addition:
its suite shells out to `git`, which the base `php:8.4-cli-alpine` image
doesn't ship:

```sh
docker run --rm -v "$PWD":/app -w /app/tools php:8.4-cli-alpine \
  sh -c "apk add --no-cache git >/dev/null 2>&1 && php vendor/bin/phpunit"
```
```

## Making a change

1. Branch off `main`.
2. Change the one package your work actually touches. Most changes stay
   entirely within a single `packages/<name>/` directory.
3. Run that package's own PHPUnit + PHPStan + Psalm (above) until clean.
4. If your change adds or changes a dependency — see the next section;
   this is the one case that isn't "just edit the code."
5. If your change is user-facing, update the relevant `docs/*.md` page in
   the same PR. This project treats docs as part of the change, not a
   follow-up — a new public method, a new config key, a new middleware,
   all get a docs update alongside the code, the same discipline every
   feature already added to this framework has followed.
6. Push, open a PR against `main`.

For a change confined to one file inside one package, that's the whole
process. The sections below cover the two things that reach outside a
single package: changing a dependency, and cutting a release.

## Changing a package's dependencies — the manifest tooling

**Never hand-edit a `packages/*/composer.json` for anything the manifest
controls** — `require`, `require-dev`, `autoload`, `bin`, and so on. Every
one of those 19 files is a *generated artifact*: the real source of truth
is `packages.manifest.json` at the repo root, and the next
`tools/generate-composer.php` run (which CI's own drift check effectively
forces, by failing if the two disagree) silently overwrites a hand
edit. Fix it in the manifest instead.

`packages.manifest.json` has a `defaults` block (PHP version floor,
license, uniform dev-tooling versions, the shared PHPStan rule set) plus
one entry per package: `requires`/`requiresDev` name *sibling package
keys* (not full Composer names — `"framework"`, not `"kinetis/framework"`),
`require` lists real third-party dependencies, and a handful of
per-package fields (`bin`, `autoloadFiles`, `namespace`/`testNamespace`,
`requireDevExtra`/`requireDevOverride`) cover the real, legitimate
differences between packages.

The full flow, exactly as used for real dependency changes in this
project's own history:

1. Edit `packages.manifest.json` — change whatever entry needs to
   change.
2. **Bump that package's `version` field in the same edit.** This is
   enforced, not optional: CI's version-bump-completeness check fails a
   PR that changes any other field in a package's manifest entry without
   also bumping its version. Pick the bump that fits — patch for a fix
   or a no-behavior-change constraint tightening, minor for a
   backward-compatible addition, major for a breaking change.
3. Regenerate every package's `composer.json` from the manifest:
   ```sh
   docker run --rm -v "$PWD":/app -w /app php:8.4-cli-alpine php tools/generate-composer.php
   ```
   Only the package(s) you actually touched will show a diff.
4. Refresh the lock file with a **scoped** `composer update` — never a
   bare one, which would drag in unrelated version bumps across that
   package's whole dependency tree:
   ```sh
   docker run --rm -v "$PWD":/app -w /app/packages/<name> composer:2 update <dep1> <dep2> ... --with-all-dependencies
   ```
   Name every entry the package's `require` *and* `require-dev` now
   list, not just what changed — unchanged entries are a harmless no-op,
   and this resolves everything jointly and consistently rather than as
   separate updates.
5. `composer validate --strict` in that package's directory, to confirm
   the lock matches.
6. Run that package's PHPUnit/PHPStan to confirm nothing broke.
7. Commit the manifest edit, the regenerated `composer.json`, and the
   updated `composer.lock` together, as one diff.

```{tip}
`tools/generate-composer.php --check` regenerates every package in
memory and diffs against what's committed without writing anything —
run it any time to confirm nothing's drifted, exactly what CI runs on
every PR.
```

**Renaming a package, or moving it to depend on a sibling it didn't
before, goes through the same flow** — it's still just a manifest field
changing, plus a version bump on everything that references it. The
`kinetis/kinetis` → `kinetis/framework` rename (core's own package,
renamed to free up its split-repo target name — see {doc}`appendix` for
why) is a real, recent example: one manifest-key edit, a regenerate, and
a patch bump on every one of the 18 dependent packages, exactly this
sequence.

### Forcing a version bump with no other change

Sometimes a package's *code* changed but nothing in its manifest entry
did — there's still a real release to cut. `tools/generate-composer.php`
has a dedicated mode for exactly this, writing *only* the `version`
field(s):

```sh
docker run --rm -v "$PWD":/app -w /app php:8.4-cli-alpine php tools/generate-composer.php \
  --bump=<key>[,<key>,...]|all --major|--minor|--patch
```

Each named package bumps relative to *its own* current version — a
`--major` bump on a package at `1.4.2` and another at `1.1.0` both
correctly land on `2.0.0`. For an exact target version instead of a
relative bump, use `--set-version=<key>=<version>`.

### The cross-manifest version-consistency check

CI also fails if two packages declare *different* version constraints
for the *same* external dependency — a real, silent-drift risk with
nothing else to catch it, since two unrelated packages never naturally
collide during an install. If a difference is genuinely deliberate, not
an oversight, add both fields to the affected package's manifest entry
rather than working around the check:

```json
"allowVersionDrift": true,
"driftReason": "why this package deliberately differs"
```

This is meant to be rare — the default is that every package sharing an
external dependency declares the same constraint for it.

## Branching, PRs, and CI

Every PR (and every push to `main`) runs the same set of GitHub Actions
workflows — see {doc}`appendix-ci` for the full reference of what each
one checks. The ones most relevant while you're actively working on a
change:

- **`ci.yml`** — `composer validate --strict`, install, `composer
  audit`, PHPUnit, PHPStan, and Psalm, one job per package (matrixed
  across PHP 8.4 and 8.5), plus a separate job building the Sphinx docs
  with `-W` (a broken docs page fails the build the same as broken code).
- **`monorepo-validate.yml`** — the four `packages.manifest.json` checks
  (cycle detection, cross-manifest version consistency, generated-file
  drift, version-bump completeness) plus `composer validate --strict`
  across all 19 packages. This is the one that actually enforces
  everything in the "changing a package's dependencies" section above —
  skip a version bump, forget to regenerate, or introduce a dependency
  cycle, and this is what catches it. Run it locally before pushing:
  ```sh
  docker run --rm -e GIT_CONFIG_COUNT=1 -e GIT_CONFIG_KEY_0=safe.directory -e GIT_CONFIG_VALUE_0='*' \
    -v "$PWD":/app -w /app php:8.4-cli-alpine \
    sh -c "apk add --no-cache git >/dev/null 2>&1 && php tools/validate-manifest.php"
  ```
- **`integration.yml`**, **`infection.yml`**, **`sonarqube.yml`**,
  **`semgrep.yml`** — real-backend verification, mutation testing,
  SonarQube Cloud static analysis, and pattern-based security scanning.
  These run automatically on every PR; there's no equivalent one-line
  local invocation for most of them (they need live service containers)
  — trust CI for these rather than trying to reproduce them locally.

`monorepo-validate.yml`'s check is registered as a GitHub branch
protection **required status check** on `main`, so a manifest/composer.json
inconsistency genuinely blocks the merge button, not just shows a red
X someone can ignore.

```{note}
GitHub branch protection rules are only enforced on a **public**
repository, or a private one on a paid plan — a free private repo, which
is this repo's normal working state, can't have a required-status-check
rule take effect even though it's configured. This doesn't change how
you should work: every check above still runs and still reports
pass/fail on every PR, and treating a red check as blocking is the right
default regardless of whether GitHub itself is currently able to enforce
it mechanically.
```

Squash-merge, rebase-merge, and a regular merge commit all work
identically here — every check in this pipeline (the manifest diff, the
version-bump trigger) compares `packages.manifest.json` between two
*commits* directly, never anything derived from individual commit
messages, so there's nothing merge-strategy-specific to worry about.

## Cutting a release

Kinetis doesn't tag the monorepo itself, and doesn't release on a fixed
schedule or in lockstep across packages — each package has its own
independent SemVer line, and **the trigger is the `version` field in
`packages.manifest.json` changing on `main`**. Nothing else — no tag, no
commit-message convention, no separate release-intent file.

`tools/release-plan.php` computes what this round's push would release,
without writing, tagging, or pushing anything — it's a read-only report:

```sh
docker run --rm -e GIT_CONFIG_COUNT=1 -e GIT_CONFIG_KEY_0=safe.directory -e GIT_CONFIG_VALUE_0='*' \
  -v "$PWD":/app -w /app php:8.4-cli-alpine \
  sh -c "apk add --no-cache git >/dev/null 2>&1 && php tools/release-plan.php"
```

It diffs the manifest at `HEAD` against `HEAD^`; every package whose
`version` differs is a release candidate, printed in dependency-respecting
publish order (a package always releases after every sibling it requires,
never before). For each candidate, it also checks that every sibling it
depends on already has a matching tag on that sibling's own split repo —
so a candidate that would fail to resolve once actually published is
caught here, against real `git ls-remote` lookups (never against
Packagist, whose own indexing delay is a separate, later concern, not
something the pipeline's internal correctness should ever depend on).

```{warning}
The actual split-and-publish step — `splitsh-lite` pushing each
package's subtree history out to its own `kinetis-dev/<name>` repo and
tagging it — is designed but not yet wired into CI. It needs real,
one-time external setup first: the 18 satellite repos actually created
under the `kinetis-dev` org, a deploy key or GitHub App token scoped to
push to all of them, and each one submitted to Packagist with its
auto-update webhook configured. Until that's done, a version bump on
`main` doesn't yet publish anything automatically — `release-plan.php`
is fully real and useful today as a dry-run/preview tool, but nothing
currently acts on its output.
```

## See also

- {doc}`appendix-ci` — the full reference for every CI workflow, what it
  checks, and what real backends `integration.yml` runs against.
- {doc}`appendix` — reference map for `kinetis/framework` itself, by
  namespace.
- {doc}`appendix-packages` — the same reference map for every satellite
  package.
