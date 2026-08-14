# Monorepo tooling

Three scripts. The first two are driven by `packages.manifest.json`
(repo root) — the canonical source of truth for every
`packages/*/composer.json`; the third is unrelated, standalone.

- `generate-composer.php` — generates each package's `composer.json`
  from the manifest. Three usages: default (writes every package),
  `--check` (regenerates in memory, diffs, never writes — what CI
  runs), `--bump` (force-bumps one or more packages' `version` field
  only).
- `validate-manifest.php` — the four checks CI runs on every PR and
  push to `main`: cycle detection, cross-manifest version consistency,
  generated-file drift, and version-bump completeness.
- `setup-docs-mcp.sh` — see "Setting up the docs MCP server" below.

Never hand-edit a `packages/*/composer.json` directly for anything the
manifest controls (`require`, `require-dev`, `autoload`, `bin`, ...) —
the next `generate-composer.php` run silently overwrites it, and CI's
drift check fails if the manifest and the committed file disagree. Fix
it in the manifest instead.

## Editing a package's dependencies — the full flow

Real, step-by-step — this is exactly the sequence used to fix a real
cross-manifest inconsistency (`storage`'s `league/flysystem` constraint
tightened to match `storage-s3`'s):

1. Edit `packages.manifest.json` — change whatever `require`/
   `requires`/`requiresDev`/etc. entry needs to change.
2. **Bump that package's `version` field in the same edit.** Required
   whenever any other field in its manifest entry changes — enforced
   by `validate-manifest.php`'s version-bump-completeness check, which
   fails a PR that changes a dependency without also bumping the
   version. Pick the bump that fits: patch for a fix or a
   no-behavior-change constraint tightening, minor for a backward-
   compatible addition, major for a breaking change.
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

Runs all four checks locally — the exact same thing CI runs. `git`
needs installing inside the container every time; the base
`php:8.4-cli-alpine` image doesn't ship it (CI does the same install
step).

## Force-bumping a version with no other change

For a release where nothing else in a package's manifest entry
changed — its source code changed but not its declared dependencies,
or a coordinated move to a new major version line across many
packages at once:

```sh
docker run --rm -v "$PWD":/app -w /app php:8.4-cli-alpine php tools/generate-composer.php \
  --bump=<key>[,<key>,...]|all --major|--minor|--patch
```

Each named package bumps *relative to its own current version* —
`--major` on a package at `1.4.2` and another at `1.1.0` both
correctly land on `2.0.0`, not one identical string forced onto every
package regardless of where it started. For an explicit target version
instead of a relative bump:

```sh
docker run --rm -v "$PWD":/app -w /app php:8.4-cli-alpine php tools/generate-composer.php \
  --set-version=<key>=<version>
```

Either form only ever writes the `version` field(s) — nothing else in
the manifest changes, which is exactly the "version-only change" case
the version-bump check always allows without further validation.

## The cross-manifest version consistency escape hatch

If two packages genuinely need different constraints for the same
external dependency (a deliberate exception, not an oversight), add
both fields to the package's manifest entry:

```json
"allowVersionDrift": true,
"driftReason": "why this package deliberately differs"
```

This is meant to be rare. The default is that every package sharing an
external dependency declares the same constraint for it — the whole
point of the check is to catch the case where two packages drift apart
silently, since neither one failing to install on its own would ever
reveal it.

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

The suite shells out to `git` (the version-bump check compares the
manifest against `HEAD^`), which `php:8.4-cli-alpine` doesn't ship —
install it in the container first, the same step the
`validate-manifest.php` invocation above already uses:

```sh
docker run --rm -v "$PWD":/app -w /app/tools composer:2 install
docker run --rm -v "$PWD":/app -w /app/tools php:8.4-cli-alpine sh -c \
  "apk add --no-cache git >/dev/null 2>&1 && git config --global safe.directory '*' && php vendor/bin/phpunit"
```

Without `git`, the affected tests still pass but each emits a
`proc_open(): posix_spawn() failed` warning.
