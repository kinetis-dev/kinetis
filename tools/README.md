# Monorepo tooling

Four entry points, plus the four modules three of them share. Those
three are driven by `packages.manifest.json` (repo root) — the canonical
source of truth for every `packages/*/composer.json`; the fourth,
`setup-docs-mcp.sh`, is unrelated and standalone.

- `generate-composer.php` — generates each package's `composer.json`
  from the manifest. Three usages: default (writes every package),
  `--check` (regenerates in memory, diffs, never writes — what CI
  runs), and the version modes `--bump`/`--set-version`, which write one
  or more packages' `version` field and nothing else.
- `validate-manifest.php` — the checks CI runs on every PR and push to
  `main`: manifest schema, cycle detection, cross-manifest version
  consistency, generated-file drift, version-bump completeness (manifest
  entries), content-bump completeness — a change to any of a package's
  own tracked files (its root `composer.lock` excepted) requires a
  version bump, since an unbumped version means the release pipeline
  never tags the new content and the split repo silently stays on the
  old tag — and workflow coverage: every package needs a `ci.yml` and an
  `infection.yml` job, and every job in those two must map back to a
  package. Absences are named in `INFECTION_EXEMPT`/`WORKFLOW_ONLY` with
  a reason, so one is a decision rather than an oversight. The same check
  requires `sonarqube.yml`'s coverage loop and `sonar-project.properties`'
  `reportPaths` to name the same packages — a package in one but not the
  other writes a report nobody reads, and shows as 0% covered while its
  tests pass.
- `release-plan.php` — computes what this round's push would release,
  read-only. Every fact it needs it either establishes or fails on: a
  tag lookup that can't reach its remote, a comparison base it can't
  read, or a dependency graph with no total order all end the run rather
  than producing a plan that leaves work out. See "Cutting a release" in
  `docs/appendix-contributing.md`.
- `setup-docs-mcp.sh` — see "Setting up the docs MCP server" below.

The shared modules, each used by more than one of the three
manifest-driven entry points:

- `version-policy.php` — the one version-transition rule. The generator
  writes only moves it allows and the validator accepts only moves it
  allows, so the two can't disagree.
- `manifest-schema.php` — the strict boundary every manifest crosses
  before anything reads it, writes a file, or contacts a remote.
- `checked-write.php` — the checked, atomic file replacement every
  generated file is written through. It writes into a private temporary
  file beside the target, proves the bytes landed, gives the file the
  mode the target should have (an existing file's own mode, `0644` for a
  new one), and only then renames. A target that is not a regular file —
  a symlink, a directory — is refused rather than replaced.
- `git-history.php` — reads the comparison base and the file-level diff
  against it, distinguishing "nothing to compare against" from "git
  couldn't read it". Every git call runs under a deadline, after which
  the child is killed and reaped; an answer that arrives incomplete — a
  failed read, output past the capture cap, a child whose reap could not
  be established — is a failure rather than a shorter success.

Never hand-edit a `packages/*/composer.json` directly for anything the
manifest controls (`require`, `require-dev`, `autoload`, `bin`, ...) —
the next `generate-composer.php` run silently overwrites it, and CI's
drift check fails if the manifest and the committed file disagree. Fix
it in the manifest instead.

## Editing a package's dependencies — the full flow

Step by step, from the manifest edit to the commit:

1. Edit `packages.manifest.json` — change whatever `require`/
   `requires`/`requiresDev`/etc. entry needs to change.
2. **Bump that package's `version` field in the same edit.** Required
   whenever any other field in its manifest entry changes — enforced
   by `validate-manifest.php`'s version-bump-completeness check, which
   fails a PR that changes a dependency without also bumping the
   version. Pick the size from the version policy in
   `docs/appendix-contributing.md`.
3. Regenerate:
   ```sh
   docker run --rm -v "$PWD":/app -w /app php:8.4-cli-alpine php tools/generate-composer.php
   ```
   Writes every package's `composer.json` — only the ones you actually
   touched will show a diff.
4. Refresh the lock file with a **scoped** `composer update` — never a
   bare one, which would drag in unrelated version bumps across the
   whole dependency tree. Name every package the manifest now lists
   for the changed package, both `require` and `require-dev`:
   ```sh
   docker run --rm -v "$PWD":/app -w /app/packages/<name> composer:2 update <name1> <name2> ... --with-all-dependencies
   ```
   Pass the package's *entire* current dependency list, not just what
   changed — unchanged entries are a harmless no-op, and anything that
   did change gets resolved jointly and correctly rather than as
   separate, possibly-inconsistent updates.
5. Validate the lock matches:
   ```sh
   docker run --rm -v "$PWD":/app -w /app/packages/<name> composer:2 validate --strict
   ```
6. Run the package's own PHPStan/PHPUnit to confirm nothing broke.
7. Commit the manifest edit + the regenerated `composer.json` + the
   updated `composer.lock` together, as one diff.

## Checking your work before pushing

```sh
docker run --rm -v "$PWD":/app -w /app php:8.4-cli-alpine sh -c \
  "apk add --no-cache git >/dev/null 2>&1 && php tools/validate-manifest.php"
```

Runs every check locally — the exact same thing CI runs. `git`
needs installing inside the container every time; the base
`php:8.4-cli-alpine` image doesn't ship it (CI does the same install
step).

Add `--base=<ref>` to compare against something other than the previous
commit. A feature branch wants its merge base with `main`, so the whole
branch is judged as one change:

```sh
BASE=$(git merge-base HEAD refs/remotes/origin/main)
docker run --rm -v "$PWD":/app -w /app php:8.4-cli-alpine sh -c \
  "apk add --no-cache git >/dev/null 2>&1 && php tools/validate-manifest.php --base=$BASE"
```

A `--base` git can't read fails the run, and so does a base that isn't a
full commit id or a plain ref name — a value carrying option, path or
range syntax, or no value at all, is refused before git sees it. The
version and content checks skip only in the two states named in
`docs/appendix-contributing.md`, and each says which; anything else that
leaves history unreadable, a shallow checkout included, fails.

## Force-bumping a version with no other change

For a release where nothing else in a package's manifest entry
changed — its source code changed but not its declared dependencies:

```sh
docker run --rm -v "$PWD":/app -w /app php:8.4-cli-alpine php tools/generate-composer.php \
  --bump=<key>[,<key>,...]|all --minor|--patch
```

Each named package bumps *relative to its own current version*: with
`--minor`, a package at `1.4.2` moves to `1.5.0` and one at `1.1.0`
moves to `1.2.0`, rather than one identical string being forced onto
both. Those two sizes are the whole set — Kinetis stays on `1.x`
throughout incubation, so there is no major bump to ask for.

For an explicit target version instead of a relative bump:

```sh
docker run --rm -v "$PWD":/app -w /app php:8.4-cli-alpine php tools/generate-composer.php \
  --set-version=<key>=<version>
```

`--set-version` is a spelling of the same move, not a way around it: it
goes through `version-policy.php` exactly as `--bump` does, so anything
`--bump` can't produce it can't write — a major, a skipped patch or
minor, a downgrade, a noncanonical spelling such as `1.4.03`, or the
current version again. A rejected key leaves the manifest untouched,
including the keys named alongside it.

The whole invocation is checked before any of it runs. An unknown
option, a repeated `--bump`, two size flags, a size flag with no
`--bump`, or `--check` alongside a mode that writes: each is refused
rather than resolved to whichever reading the code reaches first.

Either form only ever writes the `version` field(s) — nothing else in
the manifest changes, which is exactly the "version-only change" case
the version-bump check always allows without further validation.

## The cross-manifest version consistency escape hatch

When two packages need different constraints for the same external
dependency, exempt that one dependency in the package's manifest entry:

```json
"versionDriftExemptions": {
    "league/flysystem": "why this package differs"
}
```

`docs/appendix-contributing.md` states the rule the exemption is held to.
A missing or blank reason, or a dependency the package does not require,
fails the schema check.

## Setting up the docs MCP server

`setup-docs-mcp.sh` registers `kinetis/framework`'s built-in
`KinetisDocsResource` as an MCP server in Claude Code, so an agent
working in *any* project can read Kinetis's own docs directly instead
of relying on training data. It never touches this monorepo — it
installs `kinetis/framework` from Packagist into its own directory
(`~/.kinetis-mcp` by default, override with
`KINETIS_MCP_DIR`), which is exactly what makes it work: with no
local `docs/` present, `KinetisDocsResource` falls back to fetching
each page live from `kinetis-dev/kinetis`'s `main` branch on GitHub.

```sh
./tools/setup-docs-mcp.sh
```

No local checkout needed either — the script is fully self-contained
(no reference to any other file in this repo, no interactive prompts),
so it can be fetched and run directly from GitHub:

```sh
curl -fsSL https://raw.githubusercontent.com/kinetis-dev/kinetis/main/tools/setup-docs-mcp.sh | bash
```

This downloads the script's current `main` content and pipes it
straight into `bash` — identical to running the local copy above, just
without cloning the repo first. It needs no `sudo`: every step runs as
the invoking user, writing only inside `$HOME/.kinetis-mcp` (or
`$KINETIS_MCP_DIR`) and to Claude Code's own user-level config —
never a system directory. Docker itself still has to be reachable by
that user account (the same "docker: running" check either form does
first), but the script never elevates privileges to get there.

Only a running Docker daemon and the `claude` CLI need to already be on
the host — no PHP or Composer of your own, since both the install step
and the registered server run through the `composer:2` image (which
bundles a recent-enough PHP itself). The script installs
`kinetis/framework` this way, then registers the server (user scope, so
it's available in every project, not just this one — replacing any
existing registration under the same name), then runs a real
`initialize` handshake against it to confirm it actually responds
before declaring success.

The registered server checks for a newer `kinetis/framework` release
itself, at most once every 24 hours, right before it starts — a spawn
inside that window skips the check and starts immediately, so this
costs nothing on most Claude Code session starts. A failed check (no
network, for instance) is silently skipped rather than blocking the
server from starting, and doesn't count as a check — the next spawn
tries again rather than waiting out the rest of the window.

Re-running the script is also safe at any time — it reuses the
existing install directory and re-registers the server.

## Running the tools test suite

The suite shells out to `git` — the version and content checks compare
against a real commit, and several tests build a scratch repository to
exercise that — which `php:8.4-cli-alpine` doesn't ship. Install it in
the container first, the same step the `validate-manifest.php`
invocation above already uses:

```sh
docker run --rm -v "$PWD":/app -w /app/tools composer:2 install
docker run --rm -v "$PWD":/app -w /app/tools php:8.4-cli-alpine sh -c \
  "apk add --no-cache git >/dev/null 2>&1 && git config --global safe.directory '*' && php vendor/bin/phpunit"
```

Without `git`, the affected tests still pass but each emits a
`proc_open(): posix_spawn() failed` warning.
