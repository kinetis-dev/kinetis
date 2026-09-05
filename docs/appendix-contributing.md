# Appendix: Contributing to Kinetis

How the `kinetis-dev/kinetis` monorepo is structured, how to set up a
development environment, how to test a change, how the release tooling
works, and how branching, PRs, and CI fit together. Every command below
is real and runnable, using the same Docker-only toolchain throughout.

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
(CI/CD) are the only things that belong to the *monorepo* rather than to
one package.

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

A satellite package's `composer.json` declares every sibling it actually
depends on, and `composer install` inside that package's own directory
resolves and tests against exactly that declared set — the same
guarantee a real, separately-installed consumer gets. An undeclared
cross-package dependency fails to install here, inside this repo, rather
than only surfacing once someone installs the split package on its own.

```{warning}
Composer path repositories are **not transitive**. If package `C`
depends on `B`, and `B` depends on `A`, `C`'s own `composer.json` must
still declare `A` directly (with its own `path` entry) — Composer won't
walk `B`'s `repositories` array to find it. Keep this in mind when adding
a new cross-package dependency by hand rather than through the manifest
tooling below.
```

## Setting up a development environment

Nothing to install beyond Docker. Every command in this guide — and
every command CI runs — goes through
`docker run --rm -v "$PWD":/app -w /app/<dir> <image> <command>`, so
local results match CI regardless of what PHP version is on your
machine, or whether one is installed at all.

Clone the repo, then install one package's dependencies to confirm the
toolchain works:

```sh
git clone https://github.com/kinetis-dev/kinetis.git
cd kinetis

docker run --rm -v "$PWD":/app -w /app/packages/framework composer:2 install
docker run --rm -v "$PWD":/app -w /app/packages/framework php:8.4-cli-alpine php vendor/bin/phpunit
```

That's the whole setup. Each package's `vendor/` is gitignored and
independent — installing one package doesn't affect any other. There's
no repo-wide `composer.json`; install each package separately, from its
own directory.

If you're working on a satellite package, install it the same way, from
*its own* directory:

```sh
docker run --rm -v "$PWD":/app -w /app/packages/queue composer:2 install
```

Composer resolves `kinetis/framework`, and any other sibling `queue`
declares, via the `path` repository entries already in
`packages/queue/composer.json` — `../framework`, `../persistence`, and
so on. Those sibling directories always exist, since every package lives
in the same monorepo.

```{note}
If `composer install`/`update` fails with a network error talking to
`repo.packagist.org` or `api.github.com`, re-run the same command — it's
safe to retry.
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

`kinetis/pingpong` has no PHPUnit suite — it's a runnable demo
application, not a library (see {doc}`appendix-packages`). Every other
package, core included, has one.

`RedisQueue`, `SqlQueue`, `SqsQueue`, and `RabbitMqQueue` (in
`kinetis/queue`, `kinetis/queue-sqs`, and `kinetis/queue-rabbitmq`) have
no PHPUnit tests — they're tested only against real backend containers,
as standalone PHP scripts under each package's `tests-integration/`. See
{doc}`appendix-ci` for the full list and what each one checks. You don't
need to run these locally for an ordinary change; CI runs them against
real service containers on every push.

````{tip}
`tools/` — the release tooling itself — has its own PHPUnit suite too
(38 tests). Run it the same way, with one addition: its suite shells out
to `git`, which the base `php:8.4-cli-alpine` image doesn't ship:

```sh
docker run --rm -v "$PWD":/app -w /app/tools php:8.4-cli-alpine \
  sh -c "apk add --no-cache git >/dev/null 2>&1 && php vendor/bin/phpunit"
```
````

## Making a change

1. Branch off `main`.
2. Change the one package your work actually touches. Most changes stay
   entirely within a single `packages/<name>/` directory.
3. Run that package's own PHPUnit + PHPStan + Psalm (above) until clean.
4. If your change adds or changes a dependency — see the next section;
   this is the one case that isn't "just edit the code."
5. If your change is user-facing, update the relevant `docs/*.md` page
   in the same PR — a new public method, a new config key, a new piece
   of middleware, all get a docs update alongside the code.
6. Push, open a PR against `main`.

For a change confined to one file inside one package, that's the whole
process. The sections below cover the two things that reach outside a
single package — changing a dependency, and cutting a release — plus how
a change gets verified before it's pushed.

## AI-assisted development

Kinetis's own development — core and every satellite package — is done
using Claude Code and other AI tools working directly in this
repository, not as a caveat but as the actual engineering practice: the
same PHPUnit/PHPStan/Psalm/manifest verification described throughout
this page runs identically on every change regardless of who or what
wrote it. Correctness comes from what's actually checked before a
change lands, not from who typed it.

`.claude/skills/push-ready/` is a concrete result of that practice, not
just a description of it: a project-scoped
[Claude Code skill](https://docs.claude.com/en/docs/claude-code/skills)
encoding the exact pre-push checklist this repo runs by hand — scope
from the diff against the integration base, bump versions, regenerate
`composer.json`, lint, test and analyse every affected package (not just
the one touched — it walks the manifest's dependency graph), rebuild the
docs if touched, validate the manifest — stopping at the first failure
and never staging, committing, or pushing on its own. Any Claude Code
session working in this repo picks it up automatically; ask it whether a
change is ready to push, or run `/push-ready` directly. A human
contributor can read the same file at
`.claude/skills/push-ready/SKILL.md` and run the identical commands by
hand.

## Version policy

Every package moves along its own SemVer line, and every one of them
stays on `1.x` for the duration of incubation. Two bump sizes cover
every change:

- **Patch** — a fix, a maintenance pass, a no-behavior-change constraint
  tightening, a comment or README edit.
- **Minor** — a new capability, or a breaking change. Both land as a
  single minor bump.

No package is bumped to `2.0.0` during incubation. Folding breaking
changes into a minor bump is what keeps that true: a caret constraint
picks up later minors on the same major line, so a break reaches
consumers through the minor that carries it, called out in that
release's notes rather than in the version number.

A version also moves **one step at a time**. From `1.m.p` the only
versions a package can reach are `1.m.(p+1)` and `1.(m+1).0`, and a
package being added starts at `1.0.0`. Jumping `1.2.3` to `1.2.5` would
leave `1.2.4` permanently unreleased with its content folded into
`1.2.5`'s tag, since only what lands on `main` is ever tagged. That rule
lives in `tools/version-policy.php`, and both the tooling that writes a
version and the CI check that reads one go through it, so neither can
accept a move the other rejects. `--minor` and `--patch` are the whole
set of bump sizes the tooling offers.

The comparison is made end to end across everything being merged, not
commit by commit, so two bumps spread over two commits on one branch
read as the single jump they are and fail.

## Changing a package's dependencies — the manifest tooling

**Never hand-edit a `packages/*/composer.json` for anything the manifest
controls** — `require`, `require-dev`, `autoload`, `bin`, and so on.
Every one of those files is generated from `packages.manifest.json` at
the repo root; the next `tools/generate-composer.php` run overwrites a
hand edit, and CI's drift check fails if the two disagree. Fix it in the
manifest instead.

`packages.manifest.json` has a `defaults` block (PHP version floor,
license, uniform dev-tooling versions, the shared PHPStan rule set) plus
one entry per package: `requires`/`requiresDev` name *sibling package
keys* (not full Composer names — `"framework"`, not `"kinetis/framework"`),
`require` lists real third-party dependencies, and a handful of
per-package fields (`bin`, `autoloadFiles`, `namespace`/`testNamespace`,
`requireDevExtra`/`requireDevOverride`) cover the differences between
packages.

`bin` and `autoloadFiles` are package-relative paths, and the manifest
checks hold them to one spelling that every supported platform reads the
same way — these values ship in the published `composer.json` and are
resolved by whichever machine installs the package. Forward slashes
only, no leading separator, no `.` or `..` segment, no drive letter and
no backslash (a separator on Windows, an ordinary character elsewhere).
Also rejected are the characters `:`, `<`, `>`, `"`, `|`, `?` and `*`,
segments reserved as Windows device names (`con`, `nul`, `com1` and the
rest, with or without an extension), and a segment ending in a dot or a
space, since Windows strips those and turns one spelling into another.

The full flow:

1. Edit `packages.manifest.json` — change whatever entry needs to
   change.
2. Bump that package's `version` field in the same edit — required
   whenever any other field in its manifest entry changes; CI's
   version-bump-completeness check fails a PR that doesn't. Pick the
   size from the version policy above.
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

### Forcing a version bump with no other change

Sometimes a package's *code* changed but nothing in its manifest entry
did — there's still a real release to cut. `tools/generate-composer.php`
has a dedicated mode for exactly this, writing *only* the `version`
field(s):

```sh
docker run --rm -v "$PWD":/app -w /app php:8.4-cli-alpine php tools/generate-composer.php \
  --bump=<key>[,<key>,...]|all --minor|--patch
```

Each named package bumps relative to *its own* current version: with
`--minor`, a package at `1.4.2` moves to `1.5.0` and one at `1.1.0`
moves to `1.2.0`, rather than one identical string being forced onto
both. For an exact target version instead of a relative bump, use
`--set-version=<key>=<version>`; it is held to the same one-step policy,
so it can only ever spell out a move `--bump` would have made.

### The cross-manifest version-consistency check

CI also fails if two packages declare different version constraints for
the same external dependency. Where a difference is intended, exempt
that one dependency in the affected package's manifest entry rather than
working around the check:

```json
"versionDriftExemptions": {
    "league/flysystem": "why this package differs"
}
```

The exemption names one dependency and carries a reason that cannot be
blank. Every other shared dependency that package declares stays
checked, so a second drift cannot ride in on the first one's reason, and
the dependency named has to be one the package requires — otherwise the
entry exempts nothing and the schema check says so.

This is meant to be rare — the default is that every package sharing an
external dependency declares the same constraint for it, which is what
makes two of them drifting apart visible at all.

## Branching, PRs, and CI

Every PR (and every push to `main`) runs the same set of GitHub Actions
workflows — see {doc}`appendix-ci` for the full reference of what each
one checks. The ones most relevant while you're actively working on a
change:

- **`ci.yml`** — `composer validate --strict`, install, `composer
  audit`, PHPUnit, PHPStan, and Psalm, one job per package (matrixed
  across PHP 8.4 and 8.5), plus a separate job building the Sphinx docs
  with `-W` (a broken docs page fails the build the same as broken code).
- **`monorepo-validate.yml`** — the `packages.manifest.json` checks
  (manifest schema, cycle detection, cross-manifest version consistency,
  generated-file drift, version-bump completeness, content-bump
  completeness — a changed file under a package requires a version
  change in the same commit — and workflow coverage, which requires
  every package to have a `ci.yml` and `infection.yml` job, and
  `sonarqube.yml`'s coverage loop to name the same packages as
  `sonar-project.properties` reads reports for) plus `composer validate
  --strict` across every package. This is the one that enforces
  everything in the "changing a package's dependencies" section above —
  skip a version bump, forget to regenerate, or introduce a dependency
  cycle, and this is what catches it. On a pull request it compares
  against the PR's base commit, so the branch is judged as one change.
  Run it locally against your own branch's base the same way:
  ```sh
  BASE=$(git merge-base HEAD refs/remotes/origin/main)
  docker run --rm -e GIT_CONFIG_COUNT=1 -e GIT_CONFIG_KEY_0=safe.directory -e GIT_CONFIG_VALUE_0='*' \
    -v "$PWD":/app -w /app php:8.4-cli-alpine \
    sh -c "apk add --no-cache git >/dev/null 2>&1 && php tools/validate-manifest.php --base=$BASE"
  ```
  A base commit git cannot read fails the run rather than passing with
  the version checks quietly skipped, and so does a shallow checkout with
  no base named, whose HEAD reports no parent whether or not one exists.
  Two states are reported as skips that name themselves: a full checkout
  whose HEAD has no parent, and a ref's first push.
- **`integration.yml`**, **`infection.yml`**, **`sonarqube.yml`**,
  **`semgrep.yml`** — real-backend verification, mutation testing,
  SonarQube Cloud static analysis, and pattern-based security scanning.
  These run automatically on every PR; there's no equivalent one-line
  local invocation for most of them (they need live service containers)
  — trust CI for these rather than trying to reproduce them locally.
  `integration.yml` is matrixed across PHP 8.4 and 8.5 too, the same as
  `ci.yml`; `infection.yml`/`sonarqube.yml` run on 8.4 only. See
  {doc}`appendix-ci` for the exact breakdown per workflow.

`monorepo-validate.yml`'s check is registered as a GitHub branch
protection **required status check** on `main` — a manifest/composer.json
inconsistency blocks the merge button, not just a warning.

```{note}
GitHub branch protection rules are only enforced on a **public**
repository, or a private one on a paid plan. This repo's normal working
state is a free private repo, so the required-status-check rule is
configured but not currently able to take effect. Every check above
still runs and reports pass/fail on every PR regardless — treat a red
check as blocking.
```

Squash-merge, rebase-merge, and a regular merge commit all work
identically here — every check in this pipeline (the manifest diff, the
version-bump trigger) compares `packages.manifest.json` between two
*commits* directly, never anything derived from individual commit
messages, so there's nothing merge-strategy-specific to worry about.

## Cutting a release

Kinetis doesn't tag the monorepo itself, and doesn't release on a fixed
schedule or in lockstep across packages. Each package has its own
independent SemVer line, bumped per the version policy above; the
trigger is the `version` field in `packages.manifest.json` changing on
`main`.

`tools/release-plan.php` computes what this round's push would look at,
without writing, tagging, or pushing anything — it's a read-only report:

```sh
docker run --rm -e GIT_CONFIG_COUNT=1 -e GIT_CONFIG_KEY_0=safe.directory -e GIT_CONFIG_VALUE_0='*' \
  -v "$PWD":/app -w /app php:8.4-cli-alpine \
  sh -c "apk add --no-cache git >/dev/null 2>&1 && php tools/release-plan.php"
```

It diffs the manifest at `HEAD` against the comparison base — what
`main` pointed to before this push on CI, `HEAD^` locally — and adds
every package whose split repository does not yet carry its current
version as a finished release: no tag, no `main` branch, or a `main`
pointing somewhere other than the tagged commit. That second source is
what makes a first release possible at all, and what lets a later round
repair a repository whose tag landed without its branch. Candidates are
printed in dependency-respecting publish order (a package always
releases after every sibling it requires), each with whether the
siblings it depends on already have matching tags on their own split
repos, via real `git ls-remote` lookups against GitHub, not Packagist.

The plan validates the manifest before any of that and proves its own
publish order contains every candidate it found. A cycle, a sibling
reference to a package that isn't there, or any other graph fault stops
the run and says so — a nonempty candidate set never becomes an empty
plan that reads as "nothing to release". A ref lookup answers "absent"
only when `ls-remote` reached the remote and matched nothing; a refused
connection, a failed authentication or a stalled read ends the run,
since none of them are evidence about a ref.

Being a candidate is not a decision to publish. Which refs actually move
is decided per package by the publication transaction below, against the
exact publication commit and the remote state it reads for itself.

### What the publication guarantees

`release.yml` runs on every push to `main` and does four things in
order: gate, preflight, apply, and nothing else.

**One publication at a time.** The workflow groups on a constant, not on
the workflow-and-ref pair every other workflow uses, and never cancels a
run. Two pushes to `main` are two runs of one workflow on one ref, so a
per-ref group would still let them publish side by side — and the older
round finishing its push last is how a split repository ends up with
`main` behind its own tag.

**A gate for the exact commit.** `tools/release-gate.php` requires CI,
Monorepo Validate, Semgrep, and — when the commit's own diff makes it
applicable — Integration, each matched on head SHA. A run belonging to
another commit proves nothing about this one, and a green run whose
meaningful jobs were all skipped counts as a failure, since a
path-filtered workflow reports success for a commit it never examined.
The applicability of a path-filtered workflow is read from that
workflow's own filter, so the two cannot drift. Waiting is bounded, and
a gate still pending at the deadline fails.

**A whole round preflighted before anything is written.**
`tools/release-transaction.php preflight` stages the round, builds every
candidate's publication commit, reads every target repository, and
writes a transaction file naming the exact ref updates to make. A
package it cannot decide about — a tag already naming a different
commit or publishing different content, a `main` sharing no history with
the release, a remote it cannot read — ends the round with zero
publication writes, including for the packages already validated ahead
of it.

**One line of published history per package.** A split repository's
history is one commit per release. Each publication commit carries
`packages/<key>`'s tree at that round's staged release commit and names
the commit the repository published last as its parent, read from the
remote in the same round. So a release always descends from the release
before it, `main` only ever fast-forwards, and two rounds run from two
clean checkouts that have never seen each other still produce one line.
A per-prefix history rewrite cannot give that guarantee: the staged
release commit is not on `main`, so consecutive rounds would rewrite to
siblings rather than to a line and the second could not be published at
all. A version whose tag is already on the remote is verified against
what the round stages rather than rebuilt, so a round that lost its push
halfway repairs the branch around the tag it already wrote.

**Idempotent, remote-aware publication.** Git cannot update two
repositories atomically, so this does not claim to. What it guarantees
is that each repository is written by one atomic tag-and-`main` push,
that every update carries the exact remote value preflight read as a
lease, and that a round interrupted between two repositories is
completed by the next one rather than needing a human. Every remote
state is answered: an absent tag is published, a correct tag with a
stale `main` repairs `main`, a `main` that already carries a newer
release is left alone, and a tag naming a different commit is a hard
failure.

**Deterministic staging.** Every commit the round writes fixes its
identity, both dates, its parent, its message and its tree, so one
monorepo commit against one manifest and one remote state always
produces the same objects. A runtime date would make every retry a new
object and turn an idempotent republish into an endless force-push.

**One version at a time.** GitHub keeps at most one pending run per
concurrency group and discards the rest, so an intermediate commit's
validation can be dropped before it ever runs. The newest round
reconciles its own manifest version and only that one: if the version
before it (read from the manifest's own history) has no tag, the round
fails rather than publishing content no exact-SHA gate ever passed.
Release that version by re-running the release workflow on the commit
that carried it, then retry the newer one. A package whose current
version is the only one the manifest has ever carried has no predecessor
to wait for, which is what keeps a first release possible.

**A credential with one place to be.** `RELEASE_DEPLOY_TOKEN` is
provided to the apply step alone. Every other step — the plan, the gate,
the checkout, the whole preflight — runs without it, over
unauthenticated reads. Apply hands it to git through an askpass helper
reading that one child's environment, so it appears in no URL, argument,
git config value, transaction file, exception, or captured output. The
gate's own read token is narrower still: it goes only to
`https://api.github.com`, is never carried across a redirect (none are
followed, and every 3xx fails the gate), and is taken out of the
environment only once the gate's git reads are done, so no child
inherits it.

**A transaction file that is a capability, not a report.** That file is
the entire input to the one step holding a credential able to write to
every split repository. Apply parses it against a fixed shape — no
unknown or missing keys, no duplicate package or ref entries, no ref but
`refs/tags/v<version>` and `refs/heads/main`, every update publishing
that entry's own commit — and then re-derives every object it names
against the checkout that preflighted it: the commit being released, the
release commit staged from it, each package's version and published
tree, and the ancestry every lease claims. All of that runs *before* the
deploy credential is taken, so a file naming anything this checkout
cannot account for never reaches a process that could push.

## See also

- {doc}`appendix-ci` — the full reference for every CI workflow, what it
  checks, and what real backends `integration.yml` runs against.
- {doc}`appendix` — reference map for `kinetis/framework` itself, by
  namespace.
- {doc}`appendix-packages` — the same reference map for every satellite
  package.
