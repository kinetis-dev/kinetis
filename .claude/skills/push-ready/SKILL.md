---
name: push-ready
description: Verifies the Kinetis monorepo's working tree is ready to push — scopes every check from the git diff against the branch's integration base, bumps package versions once per branch, regenerates composer.json, lints/tests/analyses every touched-or-dependent package, rebuilds the docs if touched, and runs the manifest validator. Use this whenever the user asks to check if changes are ready to push, wants a pre-push or pre-commit verification pass, says something like "did I forget anything" or "is this ready," or after making edits to files under packages/ or docs/ that haven't been verified yet. Do not use this for the full CI-only suite (Infection, SonarQube, real-backend integration tests, Semgrep) — those run in CI, never locally.
---

# push-ready

The pre-push checklist, in order, stopping at the first failure. It
never stages, commits, or pushes — it proves the tree is ready and hands
control back.

Every check is scoped from the diff against the integration base. Derive
that scope; never ask which packages to check.

## 1. Base ref

One base ref decides every version question below: the commit the whole
branch integrates onto. Not `HEAD^`, which moves with each commit and
re-decides the bump from whatever the last one left behind.

```sh
BASE_REF="${KINETIS_BASE_REF:-refs/remotes/origin/main}"

git rev-parse --is-shallow-repository        # must print false
git rev-parse --verify --quiet "$BASE_REF^{commit}"
git merge-base HEAD "$BASE_REF"
```

Take the `merge-base` output as `$BASE`. The first command must print
`false` and the other two must exit zero; anything else stops the
checklist. A shallow clone, an unresolvable ref, an ambiguous name, or
no common ancestor all mean the branch cannot be measured as a whole,
and an unmeasured branch is what this skill exists to catch. Name the
failing command and stop — no `HEAD^` fallback, no substitute ref. When
the repository has no integration branch, the user names one through
`KINETIS_BASE_REF`.

`refs/remotes/origin/main` is spelled in full because a bare
`origin/main` can collide with a local branch or tag of the same name;
`--verify` then fails rather than choosing one.

## 2. The diff

```sh
git status --short
git diff --no-renames --name-only "$BASE"
git diff --no-renames --name-only --cached
```

Union the three. `--no-renames` makes a file moved between two packages
appear under both — the package that lost it needs its own bump as much
as the one that gained it. Derive:

- **Touched packages** — every distinct `packages/<name>/` in the diff.
- **Docs touched** — any `docs/*.md`.
- **Manifest touched** — `packages.manifest.json` itself.

Nothing under `packages/`, `docs/`, or `packages.manifest.json` means
there is nothing to verify. Say so and stop.

## 3. Version bump

Each touched package needs exactly **one** version move relative to
`$BASE`. Any tracked file changing under a package requires a bump, or
the release pipeline never tags the new content; two bumps on one branch
leave the intermediate version permanently unreleased, since only what
lands on `main` is tagged.

Compare each touched package's manifest `version` at `$BASE` against the
working tree:

```sh
git show "$BASE:packages.manifest.json" | grep -A3 '"<pkg>"'
```

- **Already moved since `$BASE`** — an earlier commit on the branch made
  it. Leave it. Later commits touching that package are covered by the
  same bump. If they turn a fix into a new capability, replace the one
  bump with the minor (`--set-version=<pkg>=1.<m+1>.0`) rather than
  adding a second.
- **Unmoved** — bump it once:

```sh
docker run --rm -v "$PWD":/app -w /app php:8.4-cli-alpine \
  php tools/generate-composer.php --bump=<pkg1>,<pkg2>,... --patch
```

`--patch` for fixes and maintenance, `--minor` for a new capability or a
breaking change; both of those land as one minor under the version
policy in `docs/appendix-contributing.md`, which keeps every package on
`1.x` through incubation. There is no third size. Report which size was
picked and why.

`tools/version-policy.php` is the one implementation of the rule, and
step 13 accepts only moves it allows — a missed bump, a doubled bump or
a skipped version fails there rather than reaching a push. Don't
re-derive the arithmetic by hand.

## 4. Regenerate composer.json

```sh
docker run --rm -v "$PWD":/app -w /app php:8.4-cli-alpine \
  php tools/generate-composer.php
```

Always safe to run — only packages whose manifest entry changed show a
diff. **Never hand-edit a `packages/*/composer.json`** for anything the
manifest controls.

## 5. Dependency changes only

Run this only when the manifest diff against `$BASE` touches a
`requires`/`requiresDev`/`requireDevExtra`/`suggest` block. Refresh that
package's lock with a **scoped** update naming every current dependency,
never a bare one:

```sh
docker run --rm -v "$PWD":/app -w /app/packages/<pkg> composer:2 \
  update <dep1> <dep2> ... --with-all-dependencies
docker run --rm -v "$PWD":/app -w /app/packages/<pkg> composer:2 \
  validate --strict
```

## 6. Lint every touched PHP file

Every `.php` file in step 2's diff, `tools/` included. Stop on the first
syntax error.

```sh
docker run --rm -v "$PWD":/app -w /app php:8.4-cli-alpine php -l <file>
```

## 7. Affected-package set

Wider than the touched set: every package reaching a touched one through
a `path` repository, since those are symlinks and edits run live. Walk
the manifest graph for every direct or transitive dependent.

`requires`/`requiresDev` are plain arrays of manifest keys —
`"requires": ["framework"]`, not a `kinetis/<name>: constraint` map.
Reading them as a map returns an empty dependent set, so packages that
need re-testing look unaffected.

```sh
python3 - <<'EOF'
import json
with open("packages.manifest.json") as f:
    packages = json.load(f)["packages"]

deps = {
    name: set(entry.get("requires", []) or []) | set(entry.get("requiresDev", []) or [])
    for name, entry in packages.items()
}

touched = {"<pkg1>", "<pkg2>"}  # fill in from step 2

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

Touching a foundational package pulls in most of the repo; a long run
there is the step working.

Iterate the set with a bash array (`PACKAGES=(...)`, then
`for pkg in "${PACKAGES[@]}"`) rather than an unquoted space-separated
string, so a stray `$IFS` value cannot collapse the whole list into one
silent iteration.

## 8. Test every affected package

```sh
docker run --rm -v "$PWD":/app -w /app/packages/<pkg> php:8.4-cli-alpine \
  sh -c "php vendor/bin/phpunit"
```

Stop at the first suite that fails or errors. `SKIPPED` — a real-backend
test with no live service configured — is expected, not a failure.

`tools/` is not a manifest package and is never in the affected set. Run
its own suite whenever the diff touches `tools/`; it drives real git,
which that image doesn't ship:

```sh
docker run --rm -v "$PWD":/app -w /app/tools php:8.4-cli-alpine sh -c \
  "apk add --no-cache git git-subtree >/dev/null 2>&1 && git config --global safe.directory '*' && php vendor/bin/phpunit"
```

## 9. PHPStan every affected package

```sh
docker run --rm -v "$PWD":/app -w /app/packages/<pkg> php:8.4-cli-alpine \
  php vendor/bin/phpstan analyse --no-progress --memory-limit=512M
```

## 10. Psalm every affected package that has a `psalm.xml`

```sh
docker run --rm -v "$PWD":/app -w /app/packages/<pkg> php:8.4-cli-alpine \
  sh -c "php vendor/bin/psalm --no-progress"
docker run --rm -v "$PWD":/app -w /app/packages/<pkg> php:8.4-cli-alpine \
  sh -c "php vendor/bin/psalm --no-progress --taint-analysis"
```

## 11. Docs build, only if docs touched

Must report `build succeeded`.

```sh
docker run --rm -v "$PWD/docs":/app -w /app python:3.12-slim \
  bash -c "pip install -q -r requirements.txt && sphinx-build -M html . _build -W --keep-going"
```

## 12. Changed code examples

```sh
git diff "$BASE" -- docs packages/*/README.md | grep -E "^\+.*\`\`\`|^-.*\`\`\`"
```

Empty output skips this step. Otherwise execute or `php -l` each changed
example — every docs and README example ships checked, and reading one
is not checking it.

## 13. Manifest validation

Run after steps 3–5 have landed, so it judges the settled state, and
pass the same `$BASE`, so the branch is judged as one change:

```sh
docker run --rm -e GIT_CONFIG_COUNT=1 -e GIT_CONFIG_KEY_0=safe.directory -e GIT_CONFIG_VALUE_0='*' \
  -v "$PWD":/app -w /app php:8.4-cli-alpine sh -c \
  "apk add --no-cache git >/dev/null 2>&1 && php tools/validate-manifest.php --base=$BASE"
```

Every check must read `OK`: `manifest-schema`, `cycle`,
`version-consistency`, `generated-drift`, `version-bump`,
`content-bump`, `workflow-coverage`.

`version-bump` and `content-bump` are the two that read `$BASE`, and
between them they enforce the whole of step 3. A `--base` git cannot
read fails the run rather than skipping them. `Skipped` here means
`$BASE` never reached the container — fix the invocation rather than
accepting the pass.

## 14. Writing-rule and duplication pass

Read the branch's own diff against `$BASE`, not the whole repo, for two
things.

**Was/now narration, hedging, other-framework mentions** on added lines:

```sh
git diff "$BASE" -- docs packages | grep '^+' | grep -viE '^\+\+\+' | grep -niE \
  "previously|used to be|no longer opens|was reverted|the previous |reversed from|deprecated in favor|used to (drop|lose|close|skip|throw|return|require|need)|honest caveat|to be fair|admittedly|unfortunately|sadly|Laravel|Symfony's own|Django|CodeIgniter|CakePHP"
```

The pattern over- and under-fires, so every hit needs a read — "no
longer opens" can be a present-tense hypothetical rather than history.
It is a starting point, not a verdict.

**Duplicated normative claims** — a new ordering guarantee, numeric
default, "throws when" condition or composition claim that already
exists in a class docblock, a docs page, an appendix entry or a README
should reference the authoritative copy instead of adding a second one
that can drift. No grep for this half: recognize the shape of a
behavioral claim, then check `docs/appendix.md`,
`docs/appendix-packages.md`, and the relevant page or README.

## 15. Final read of the diff

```sh
git status --short
git diff
```

Look for anything unintended — a stray debug file, a secret, a file that
does not belong to this change.

## 16. Report and stop

Summarize what was checked, what passed, and what was skipped and why.
**Do not stage, commit, or push** — that is the user's call, made
separately every time.

## Out of scope

CI-only; running them before every push defeats the point of CI:

- Infection (mutation testing)
- SonarQube Cloud scan
- Real-backend integration tests (`tests-integration/`, or any test
  needing `MYSQL_HOST`/`POSTGRES_HOST`/`REDIS_HOST` and the like set)
- Semgrep
