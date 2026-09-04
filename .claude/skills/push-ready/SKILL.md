---
name: push-ready
description: Verifies the Kinetis monorepo's working tree is ready to push — scopes every check from the git diff against the branch's integration base, bumps package versions once per branch, regenerates composer.json, lints/tests/analyses every touched-or-dependent package, rebuilds the docs if touched, and runs the manifest validator. Use this whenever the user asks to check if changes are ready to push, wants a pre-push or pre-commit verification pass, says something like "did I forget anything" or "is this ready," or after making edits to files under packages/ or docs/ that haven't been verified yet. Do not use this for the full CI-only suite (Infection, SonarQube, real-backend integration tests, Semgrep) — those run in CI, never locally.
---

# push-ready

Runs the same checklist this session has hand-run before every push, in
order, stopping at the first failure. It never stages, commits, or
pushes — it only proves the tree is ready, then hands control back.

Everything is scoped from the diff against the integration base — never
ask the user which packages to check; derive it.

## 0. Establish the base ref

Every version decision below is made against **one** base ref: the
commit the complete feature branch will be integrated onto. Not `HEAD^`,
which moves with every commit and re-decides the bump from whatever the
last commit happened to leave behind.

```sh
BASE_REF="${KINETIS_BASE_REF:-refs/remotes/origin/main}"

git rev-parse --is-shallow-repository        # must print false
git rev-parse --verify --quiet "$BASE_REF^{commit}"
git merge-base HEAD "$BASE_REF"
```

Take the `git merge-base` output as `$BASE` and use it everywhere below.

**The first must print `false`, the other two must exit zero, and the
whole checklist stops otherwise.** A shallow clone, a base ref that
doesn't resolve, an ambiguous name (`--verify` fails on one rather than
picking a side), or no common ancestor all mean the branch cannot be
measured as a whole — and a version decision made without that
measurement is the one this skill exists to prevent. Say which command
failed and stop; do not fall back to `HEAD^`, and do not guess a
different ref. If the repository has no integration branch here, the
user has to name one via `KINETIS_BASE_REF`.

`refs/remotes/origin/main` is spelled in full because a bare
`origin/main` can collide with a local branch or tag of the same name;
`--verify` then fails rather than silently choosing one.

## 1. Establish the diff

```sh
git status --short
git diff --no-renames --name-only "$BASE"
git diff --no-renames --name-only --cached
```

Union the diff against `$BASE` with anything staged or untracked.
`--no-renames` is what makes a file moved between two packages appear
under both — the package that lost it needs its own bump just as much as
the one that gained it. From this, derive:

- **Touched packages**: every distinct `packages/<name>/` directory
  appearing in the diff.
- **Docs touched**: whether any `docs/*.md` changed.
- **Manifest touched**: whether `packages.manifest.json` itself changed.

If the diff touches nothing under `packages/`, `docs/`, or
`packages.manifest.json`, say so and stop — there is nothing for this
skill to verify.

## 2. Version bump

For every touched package, exactly **one** version bump must exist
relative to `$BASE` — no more and no less. Any tracked file changing
under a package requires a bump, or the release pipeline never tags the
new content; two bumps on one branch leave the intermediate version
permanently unreleased, since only what lands on `main` is ever tagged.

For each touched package, compare its manifest `version` at `$BASE`
against the working tree:

```sh
git show "$BASE:packages.manifest.json" | grep -A3 '"<pkg>"'
```

- **Already changed since `$BASE`** — an earlier commit on this branch
  made the bump. Leave it alone. Later review commits touching more
  files in that same package are covered by it; bumping again here is
  the mistake this step exists to prevent. If those later commits turned
  a fix into a new capability, replace that one bump with the minor —
  `--set-version=<pkg>=1.<m+1>.0` — rather than adding a second one.
- **Unchanged since `$BASE`** — bump it once:

```sh
docker run --rm -v "$PWD":/app -w /app php:8.4-cli-alpine \
  php tools/generate-composer.php --bump=<pkg1>,<pkg2>,... --patch
```

Use `--patch` by default — fixes, maintenance, docblock/comment/small-fix
passes. Use `--minor` when the diff adds a new capability *or* breaks
public behavior a consumer depends on; both are one minor bump under this
repo's version policy (`docs/appendix-contributing.md`), which keeps every
package on `1.x` through incubation. Say in the final report which size
was picked and why, rather than picking one silently.

There is no third size. `tools/version-policy.php` is the one
implementation of the rule and `docs/appendix-contributing.md` states
it; the generator writes only moves it allows and step 12 accepts only
moves it allows. So a missed bump, a doubled bump, or a skipped version
fails there rather than reaching a push — don't re-derive the arithmetic
by hand here.

## 3. Regenerate composer.json

```sh
docker run --rm -v "$PWD":/app -w /app php:8.4-cli-alpine \
  php tools/generate-composer.php
```

Always safe to run — only packages whose manifest entry actually
changed will show a diff. **Never hand-edit a `packages/*/composer.json`
directly** for anything the manifest controls.

## 4. If a dependency itself changed

Only if `packages.manifest.json`'s diff against `$BASE` touches a
`requires`/`requiresDev`/`requiresDevExtra`/`suggest` block: refresh
that package's lock with a **scoped** update (never bare) naming every
current dependency, then validate:

```sh
docker run --rm -v "$PWD":/app -w /app/packages/<pkg> composer:2 \
  update <dep1> <dep2> ... --with-all-dependencies
docker run --rm -v "$PWD":/app -w /app/packages/<pkg> composer:2 \
  validate --strict
```

## 5. Lint every touched PHP file

Every `.php` file in step 1's diff, `tools/` included.

```sh
docker run --rm -v "$PWD":/app -w /app php:8.4-cli-alpine php -l <file>
```

Stop immediately on any syntax error.

## 6. Compute the affected-package set

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

touched = {"<pkg1>", "<pkg2>"}  # fill in from step 1

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

## 7. Test every affected package

```sh
docker run --rm -v "$PWD":/app -w /app/packages/<pkg> php:8.4-cli-alpine \
  sh -c "php vendor/bin/phpunit"
```

Stop at the first package whose suite fails or errors. A `SKIPPED`
result (real-backend integration tests with no live service configured)
is expected and not a failure.

## 8. PHPStan every affected package

```sh
docker run --rm -v "$PWD":/app -w /app/packages/<pkg> php:8.4-cli-alpine \
  php vendor/bin/phpstan analyse --no-progress --memory-limit=512M
```

## 9. Psalm every affected package

Only for packages that have a `psalm.xml` (not every package does —
check first):

```sh
docker run --rm -v "$PWD":/app -w /app/packages/<pkg> php:8.4-cli-alpine \
  sh -c "php vendor/bin/psalm --no-progress"
docker run --rm -v "$PWD":/app -w /app/packages/<pkg> php:8.4-cli-alpine \
  sh -c "php vendor/bin/psalm --no-progress --taint-analysis"
```

## 10. Docs build, only if docs touched

```sh
docker run --rm -v "$PWD/docs":/app -w /app python:3.12-slim \
  bash -c "pip install -q -r requirements.txt && sphinx-build -M html . _build -W --keep-going"
```

Must say `build succeeded` with zero warnings treated as errors.

## 11. New/changed docs or README code examples

Check whether any fenced code block actually changed, not just prose
around it:

```sh
git diff "$BASE" -- docs packages/*/README.md | grep -E "^\+.*\`\`\`|^-.*\`\`\`"
```

If that's non-empty, actually execute or lint the changed example —
don't ship an unverified snippet (this has shipped broken twice
before: a `DatabaseTruncation` example missing its abstract method, a
`ShipmentTracker` example missing a declared property). A quick
standalone `php -l` or a real run against a throwaway fixture is
enough; reading it and assuming it's right is not. Empty output means
skip this step.

## 12. Manifest validation

Run last, after 2–4 have landed, so it checks the settled state. Pass
the same `$BASE` step 0 established, so the branch is judged as one
change rather than one commit:

```sh
docker run --rm -e GIT_CONFIG_COUNT=1 -e GIT_CONFIG_KEY_0=safe.directory -e GIT_CONFIG_VALUE_0='*' \
  -v "$PWD":/app -w /app php:8.4-cli-alpine sh -c \
  "apk add --no-cache git >/dev/null 2>&1 && php tools/validate-manifest.php --base=$BASE"
```

Every check must read `OK`: `manifest-schema`, `cycle`,
`version-consistency`, `generated-drift`, `version-bump`,
`content-bump`, `workflow-coverage`.

`version-bump` and `content-bump` are the two that read `$BASE`. Between
them they enforce the whole rule step 2 works to: every touched package
bumped, each by exactly one step of `tools/version-policy.php`. A
`--base` git cannot read fails the run rather than skipping those two,
and so does an empty one — the same fail-closed behavior step 0 has. If
they report `Skipped` here, `$BASE` was never set; fix the invocation
rather than accepting the pass.

## 13. Writing-rule and duplication pass

Re-read the branch's own diff against `$BASE` (not the whole repo —
that's a separate, heavier sweep, not part of this checklist) for two
things:

**Was/now narration, hedging tone, other-framework mentions** — added
lines only:

```sh
git diff "$BASE" -- docs packages | grep '^+' | grep -viE '^\+\+\+' | grep -niE \
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

## 14. Final safety review

```sh
git status --short
git diff
```

Look for anything unintended: a stray debug file, an accidentally
included secret, a file that shouldn't be part of this change.

## 15. Report and stop

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
