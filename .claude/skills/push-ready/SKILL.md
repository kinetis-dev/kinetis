---
name: push-ready
description: Verifies the Kinetis monorepo's working tree is actually ready to push — scopes every check from the real git diff, bumps package versions, regenerates composer.json, lints/tests/analyses every touched-or-dependent package, rebuilds the docs if touched, and runs the manifest validator. Use this whenever the user asks to check if changes are ready to push, wants a pre-push or pre-commit verification pass, says something like "did I forget anything" or "is this ready," or after making edits to files under packages/ or docs/ that haven't been verified yet. Do not use this for the full CI-only suite (Infection, SonarQube, real-backend integration tests, Semgrep) — those run in CI, never locally.
---

# push-ready

Runs the same checklist this session has hand-run before every push, in
order, stopping at the first failure. It never stages, commits, or
pushes — it only proves the tree is ready, then hands control back.

Everything is scoped from the real diff — never ask the user which
packages to check; derive it.

## 0. Establish the diff

```sh
git status --short
git diff --name-only HEAD
git diff --name-only --cached
```

Union staged + unstaged + untracked. From this, derive:

- **Touched packages**: every distinct `packages/<name>/` directory
  appearing in the diff.
- **Docs touched**: whether any `docs/*.md` changed.
- **Manifest touched**: whether `packages.manifest.json` itself changed.

If the diff touches nothing under `packages/`, `docs/`, or
`packages.manifest.json`, say so and stop — there is nothing for this
skill to verify.

## 1. Version bump

For every touched package, a version bump must exist in
`packages.manifest.json` covering this change (this repo's own
`content-bump-completeness` rule: any tracked file changing under a
package requires a bump, or the release pipeline never tags the new
content — this has been the single most common thing actually forgotten
before a push this session).

Check whether `packages.manifest.json`'s diff already bumped every
touched package's `version`. If any touched package's version is
unchanged since the last commit, bump it:

```sh
docker run --rm -v "$PWD":/app -w /app php:8.4-cli-alpine \
  php tools/generate-composer.php --bump=<pkg1>,<pkg2>,... --patch
```

Use `--patch` by default (matches this repo's own precedent for
docblock/comment/small-fix passes — "Text cleanup"-class changes). If
the diff clearly adds a new capability or changes public behavior in a
way a consumer would notice, use `--minor` instead and say so in the
final report rather than silently picking one — don't guess at
`--major`; ask first if a change looks breaking.

## 2. Regenerate composer.json

```sh
docker run --rm -v "$PWD":/app -w /app php:8.4-cli-alpine \
  php tools/generate-composer.php
```

Always safe to run — only packages whose manifest entry actually
changed will show a diff. **Never hand-edit a `packages/*/composer.json`
directly** for anything the manifest controls.

## 3. If a dependency itself changed

Only if `packages.manifest.json`'s diff touches a `requires`/
`requiresDev`/`requiresDevExtra`/`suggest` block: refresh that
package's lock with a **scoped** update (never bare) naming every
current dependency, then validate:

```sh
docker run --rm -v "$PWD":/app -w /app/packages/<pkg> composer:2 \
  update <dep1> <dep2> ... --with-all-dependencies
docker run --rm -v "$PWD":/app -w /app/packages/<pkg> composer:2 \
  validate --strict
```

## 4. Lint every touched PHP file

```sh
docker run --rm -v "$PWD":/app -w /app php:8.4-cli-alpine php -l <file>
```

Stop immediately on any syntax error.

## 5. Compute the affected-package set

Not just the touched packages — also every package that reaches a
touched one through a `path` repository, since edits there run live
(these are symlinks, not copies). Walk `packages.manifest.json`'s
dependency graph and find every package that depends, directly or
transitively, on a touched package.

**`requires`/`requiresDev` are plain arrays of manifest keys** —
`"requires": ["framework"]`, not a `kinetis/<name>: constraint` map.
Getting this schema wrong silently returns an empty dependent set,
which is a real, dangerous false negative (packages that need
re-testing look unaffected instead). Compute it with:

```sh
python3 - <<'EOF'
import json
with open("packages.manifest.json") as f:
    packages = json.load(f)["packages"]

deps = {
    name: set(entry.get("requires", []) or []) | set(entry.get("requiresDev", []) or [])
    for name, entry in packages.items()
}

touched = {"<pkg1>", "<pkg2>"}  # fill in from step 0

affected = set(touched)
changed = True
while changed:
    changed = False
    for name, dep_names in deps.items():
        if name not in affected and dep_names & affected:
            affected.add(name)
            changed = True

print(sorted(affected))
EOF
```

(This mattered concretely this session, twice: an `AppScope.php` edit
needed `mcp`, `auth-jwt`, and `auth` re-tested too — none of them were
touched files themselves, but all three consume it live. And touching
`framework` itself pulled in 15 more packages, 24 of 25 total — expect
a long run whenever a foundational package is touched; that's this
step working correctly, not a bug.)

**Shell hygiene for every step below that loops over this set in one
command**: `unset IFS` before the loop. A stray value in `$IFS`
(observed once this session — a NUL byte somehow present) silently
breaks `for pkg in $LIST`-style word splitting, collapsing the whole
list into one iteration with no error. Prefer a bash array
(`PACKAGES=(...)`, `for pkg in "${PACKAGES[@]}"`) over an unquoted
space-separated string regardless.

## 6. Test every affected package

```sh
docker run --rm -v "$PWD":/app -w /app/packages/<pkg> php:8.4-cli-alpine \
  sh -c "php vendor/bin/phpunit"
```

Stop at the first package whose suite fails or errors. A `SKIPPED`
result (real-backend integration tests with no live service configured)
is expected and not a failure.

## 7. PHPStan every affected package

```sh
docker run --rm -v "$PWD":/app -w /app/packages/<pkg> php:8.4-cli-alpine \
  php vendor/bin/phpstan analyse --no-progress --memory-limit=512M
```

## 8. Psalm every affected package

Only for packages that have a `psalm.xml` (not every package does —
check first):

```sh
docker run --rm -v "$PWD":/app -w /app/packages/<pkg> php:8.4-cli-alpine \
  sh -c "php vendor/bin/psalm --no-progress"
docker run --rm -v "$PWD":/app -w /app/packages/<pkg> php:8.4-cli-alpine \
  sh -c "php vendor/bin/psalm --no-progress --taint-analysis"
```

## 9. Docs build, only if docs touched

```sh
docker run --rm -v "$PWD/docs":/app -w /app python:3.12-slim \
  bash -c "pip install -q -r requirements.txt && sphinx-build -M html . _build -W --keep-going"
```

Must say `build succeeded` with zero warnings treated as errors.

## 10. New/changed docs or README code examples

Check whether any fenced code block actually changed, not just prose
around it:

```sh
git diff -- docs packages/*/README.md | grep -E "^\+.*\`\`\`|^-.*\`\`\`"
```

If that's non-empty, actually execute or lint the changed example —
don't ship an unverified snippet (this has shipped broken twice
before: a `DatabaseTruncation` example missing its abstract method, a
`ShipmentTracker` example missing a declared property). A quick
standalone `php -l` or a real run against a throwaway fixture is
enough; reading it and assuming it's right is not. Empty output means
skip this step.

## 11. Manifest validation

Run last, after 1–3 have actually landed, so it's checking the real
settled state:

```sh
docker run --rm -v "$PWD":/app -w /app php:8.4-cli-alpine sh -c \
  "apk add --no-cache git >/dev/null 2>&1 && git config --global --add safe.directory /app && php tools/validate-manifest.php"
```

All six checks (`cycle`, `version-consistency`, `generated-drift`,
`version-bump`, `content-bump`, `workflow-coverage`) must read `OK`.

## 12. Writing-rule and duplication pass

Re-read the diff itself (not the whole repo — that's a separate,
heavier sweep, not part of this checklist) for two things:

**Was/now narration, hedging tone, other-framework mentions** — added
lines only:

```sh
git diff -- docs packages | grep '^+' | grep -viE '^\+\+\+' | grep -niE \
  "previously|used to be|no longer opens|was reverted|the previous |reversed from|deprecated in favor|used to (drop|lose|close|skip|throw|return|require|need)|honest caveat|to be fair|admittedly|unfortunately|sadly|Laravel|Symfony's own|Django|CodeIgniter|CakePHP"
```

Every hit needs a manual read — this pattern list over- and
under-fires (e.g. "no longer opens" can be a correct present-tense
hypothetical, not history; see `Route.php`'s delimiter docblock for a
confirmed-clean example of exactly that false-positive shape). It's a
starting point, not a verdict.

**Duplicated normative claims** — a new ordering guarantee, numeric
default, "throws when" condition, or "composes correctly with X" claim
that already exists elsewhere (a class docblock, a docs page, an
appendix entry, a README) should point back to the authoritative one
instead of adding a second copy that can drift from it later. No
mechanical grep for this half — it needs recognizing the *shape* of a
behavioral claim, then checking `docs/appendix.md`,
`docs/appendix-packages.md`, and the relevant docs page/README for a
second copy of it.

## 13. Final safety review

```sh
git status --short
git diff
```

Look for anything unintended: a stray debug file, an accidentally
included secret, a file that shouldn't be part of this change.

## 14. Report and stop

Summarize what was checked and what passed/was skipped-and-why. **Do
not stage, commit, or push anything** — that's the user's call, made
separately, every time.

## Explicitly out of scope

Never run as part of this skill — these are CI-only, and running them
locally before every push defeats the point of having CI do it:

- Infection (mutation testing)
- SonarQube Cloud scan
- Real-backend integration tests (`tests-integration/`, or any test
  needing `MYSQL_HOST`/`POSTGRES_HOST`/`REDIS_HOST`/etc. actually set)
- Semgrep / CodeQL-equivalent scanning
