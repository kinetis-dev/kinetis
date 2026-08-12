# Monorepo tooling

Two scripts, driven by `packages.manifest.json` (repo root) — the
canonical source of truth for every `packages/*/composer.json`.

- `generate-composer.php` — generates each package's `composer.json`
  from the manifest. Three usages: default (writes every package),
  `--check` (regenerates in memory, diffs, never writes — what CI
  runs), `--bump` (force-bumps one or more packages' `version` field
  only).
- `validate-manifest.php` — the four checks CI runs on every PR and
  push to `main`: cycle detection, cross-manifest version consistency,
  generated-file drift, and version-bump completeness.

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
