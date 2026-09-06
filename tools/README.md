# Monorepo tooling

Seven files. Five are commands driven by `packages.manifest.json` (repo
root) — the canonical source of truth for every `packages/*/composer.json`
— one is the rule those commands share, and `setup-docs-mcp.sh` is
unrelated and standalone.

- `version-policy.php` — the one version-transition rule, required by
  the generator and the validator so the two can't disagree. Kinetis stays
  on `1.x` through incubation: a version moves exactly one step, either
  `1.m.p -> 1.m.(p+1)` or `1.m.p -> 1.(m+1).0`, a new package starts at
  `1.0.0`, and a major or a skipped version is refused. Skipping is
  refused because the release pipeline tags what the manifest says: a
  version nothing ever set is a version nothing ever tags.
- `generate-composer.php` — generates each package's `composer.json`
  from the manifest. Four usages: default (writes every package),
  `--check` (regenerates in memory, diffs, never writes — what CI runs),
  the version modes `--bump`/`--set-version`, which write one or more
  packages' `version` field and nothing else, and `--release`/
  `--release-write`, which resolve sibling requirements to published
  version constraints instead of path repositories.
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
  tests pass. It also owns the two history reads the version and content
  checks need: the comparison base, and the file-level diff against it.
- `release-plan.php` — computes which packages this round has to look
  at, read-only and unauthenticated. A package is a candidate when its
  version changed since the comparison base, or when its split
  repository does not yet carry the current version as a finished
  release — no tag, no main branch, or a main branch pointing somewhere
  other than the tagged commit. Each candidate is reported with two
  problems answered: whether the siblings it requires are tagged on
  their own split repos, and whether its version is the next one its
  repository may publish — one step above the highest version already
  tagged there, or `1.0.0` when it has none. Every fact it needs it
  either establishes or fails on: a ref lookup that can't reach its
  remote, a comparison base it can't read, or a dependency graph with no
  total order all end the run rather than producing a plan that leaves
  work out.
- `release-gate.php` — decides whether the exact commit being released
  passed CI, Monorepo Validate and Semgrep, the three workflows every
  push to `main` runs unconditionally. It accepts a run as evidence only
  when it is a push to `main`, at that exact head SHA, of one of those
  three workflow *files* — identified by the path GitHub reports, since
  a display name is whatever the workflow's `name:` field said at the
  time. A merged pull request's own runs sit at the same head SHA and
  answer for none of the three. Waiting is bounded, and every state that
  is not a proven success ends the run.
- `release-publish.php` — the publication itself, and the only tool that
  writes anything outside this repository. For each candidate in plan
  order it stages that package's release-mode `composer.json` and drops
  its tracked `composer.lock` as one commit in the runner's own
  disposable checkout, splits `packages/<key>` out of that commit with
  `git subtree split`, and updates the split repository's `main` and its
  `v<version>` tag in a single atomic push. Every synthetic commit is
  authored and dated by the monorepo commit being released, so one
  source commit and one plan always describe the same split commits: a
  repository already carrying both refs at this round's split commit is
  skipped, and a round interrupted partway is finished by a rerun rather
  than offered a second commit for a published version. `main` is
  written under a lease naming the commit the round read for it, so a
  branch that moved in between fails the push instead of being
  overwritten; the tag is never forced, and a tag already naming
  different content fails the run and is repaired by hand. Nothing is
  published unless the commit being split is still this repository's
  `main`, so a workflow rerun of a superseded commit cannot move a split
  repository backwards.
- `setup-docs-mcp.sh` — see "Setting up the docs MCP server" below.

See "Cutting a release" in `docs/appendix-contributing.md` for how the
three release tools fit together.

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

A `--base` git can't read fails the run. The version and content checks
skip on the repository's first commit, which has nothing behind it;
anything else that leaves history unreadable, a shallow checkout
included, fails.

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
option, a repeated `--bump`, `--minor` and `--patch` together, a size
flag with no `--bump`, or `--check` alongside a mode that writes: each is
refused rather than resolved to whichever reading the code reaches
first.

Either form only ever writes the `version` field(s) — nothing else in
the manifest changes, which is exactly the "version-only change" case
the version-bump check always allows without further validation.

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
against a real commit, and several tests build a scratch repository, a
local remote and a real split to exercise that — which
`php:8.4-cli-alpine` doesn't ship. `git subtree` is its own Alpine
package alongside it, and it is the publication's splitter:

```sh
docker run --rm -v "$PWD":/app -w /app/tools composer:2 install
docker run --rm -v "$PWD":/app -w /app/tools php:8.4-cli-alpine sh -c \
  "apk add --no-cache git git-subtree >/dev/null 2>&1 && git config --global safe.directory '*' && php vendor/bin/phpunit"
```

Without them the publication tests fail rather than skip: what they
prove is what git does with the commands the publication builds.
