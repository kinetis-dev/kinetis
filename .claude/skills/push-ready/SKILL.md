---
name: push-ready
description: Verifies the Kinetis monorepo's working tree is ready to push — scopes every check from the git diff against the branch's integration base, bumps package versions once per branch, regenerates composer.json, lints/tests/analyses every touched-or-dependent package, rebuilds the docs if touched, and runs the manifest validator. Use this whenever the user asks to check if changes are ready to push, wants a pre-push or pre-commit verification pass, says something like "did I forget anything" or "is this ready," or after making edits to files under packages/, docs/, tools/ or .github/workflows/ that haven't been verified yet. Do not use this for the full CI-only suite (Infection, SonarQube, real-backend integration tests, Semgrep) — those run in CI, never locally.
---

# push-ready

The pre-push checklist, in order, stopping at the first failure. It
never stages, commits, or pushes — it proves the tree is ready and hands
control back.

Scope comes from the diff against the integration base. Derive it;
never ask which packages to check.

## 1. Base ref

One base ref decides every version question below: the commit the whole
branch integrates onto. Not `HEAD^`, which moves with each commit and
re-decides the bump from whatever the last one left behind.

```sh
BASE_REF="${KINETIS_BASE_REF:-refs/remotes/origin/main}"

git rev-parse --is-shallow-repository        # must print false
[ -n "$KINETIS_BASE_REF" ] || git fetch --quiet origin main
git rev-parse --verify --quiet "$BASE_REF^{commit}"
BASE=$(git rev-parse --verify "$BASE_REF^{commit}")
git merge-base --is-ancestor "$BASE" HEAD    # must exit 0
```

The first command must print `false` and the rest must exit zero;
anything else stops the checklist. A shallow clone, an unresolvable ref
or an ambiguous name all mean the branch cannot be measured as a whole,
and an unmeasured branch is what this skill exists to catch. Name the
failing command and stop — no `HEAD^` fallback, no substitute ref.

The fetch runs only for the default ref, whose remote and branch are
known. A `KINETIS_BASE_REF` the user names is taken as given and never
parsed for a remote to refresh: guessing one from a ref name is how a
checklist ends up fetching somewhere the user did not ask it to.

`--is-ancestor` is the safety property, not a formality: `$BASE` is the
integration tip, and a branch that does not contain it is measuring
against a `main` that has already moved. Its next version can be one
another branch consumed and released, and only what lands on `main` is
tagged, so the collision surfaces as a version that never tags. Stop and
say the branch needs rebasing or merging onto `$BASE` first. Because the
tip is an ancestor, it is also the merge base — one commit answers both
questions.

`refs/remotes/origin/main` is spelled in full because a bare
`origin/main` can collide with a local branch or tag of the same name;
`--verify` then fails rather than choosing one.

### Carrying `$BASE` and `$COMMON`

Both are resolved once, here, and read by steps 2, 3, 5, 6, 8 and 12-16.
A shell variable does not survive from one tool call to the next: run
every command that needs one in the same shell as the command that set
it, or echo both now and substitute the recorded literals into each
later command.

An empty one does not always fail loudly. `git log "..HEAD"` is an empty
range that prints nothing and exits zero, so steps 14 and 16 would report
a clean pass over no commits at all, and in `git diff "" | grep ...` the
failure happens inside the pipe while grep's own "no match" becomes the
result. Neither reads as a broken invocation.

### The worktree mount

In an ordinary checkout `.git` is a directory inside `$PWD`, so the
`-v "$PWD":/app` mount every command below carries already holds it. In
a linked worktree `.git` is a *file* naming an absolute gitdir under the
main checkout, which that mount does not reach. Resolve it once:

```sh
COMMON=$(git rev-parse --path-format=absolute --git-common-dir)
```

**Every container command that reads git history takes
`-v "$COMMON":"$COMMON"` when run from a linked worktree** — mounted at
its own absolute path, because that is the path the `.git` file names.
That is every Composer command (it shells out to git to resolve a `path`
repository's version), `tools/validate-manifest.php`, and `tools/`'s own
suite. Steps 5, 8 and 13 name it rather than repeating the reason.

The two failure modes differ. `validate-manifest.php` and the `tools/`
suite stop outright — `Could not read HEAD`, `not a git repository`.
Composer degrades instead: it warns `could not detect the root package
version, defaulting to '1.0.0'` and carries on, which resolves anyway
while sibling constraints are `dev-main`, and is a silent wrong answer
the moment one is not.

## 2. The diff

```sh
git diff --no-renames --name-only "$BASE"
git status --porcelain --no-renames | cut -c4-
```

Union the two. The first covers everything tracked — committed, staged
and unstaged alike, since `git diff <commit>` compares the working tree
against that commit. The second is what adds untracked files, which the
first cannot see. `--no-renames` on both makes a file moved between two
packages appear under both — the package that lost it needs its own bump
as much as the one that gained it — and keeps an `old -> new` pair out of
the path list step 6 reads. Derive:

- **Touched packages** — every distinct `packages/<name>/` in the diff.
- **Docs touched** — anything under `docs/`, not only `docs/*.md`:
  `conf.py`, `requirements.txt`, `_static/`, `_templates/` and
  `_extra/` all change what step 11 builds.
- **Manifest touched** — `packages.manifest.json` itself.
- **Tooling touched** — anything under `tools/`, which has its own
  suite (step 8) and no manifest entry.
- **Workflows touched** — anything under `.github/workflows/`, which
  step 13's `workflow-coverage` check reads and step 5b lints.

These scopes decide which of steps 3-13 have work to do; an empty
scope skips its own steps and nothing else. Everything the diff holds
outside them — `README.md`, `CLAUDE.md`, `.claude/skills/`, any other
repository guidance or tracked file — still runs steps 12 and 14-17.
Those are the checks that hold for any change at all: an example that
must work, the writing rules, a clean diff, and human-only history. An
empty diff, and only an empty diff, is nothing to verify.

## 3. Version bump

Each touched package needs exactly **one** version move relative to
`$BASE`. Any changed path under a package requires a bump — an untracked
addition from step 2's union as much as an edit to a tracked file — or
the release pipeline never tags the new content; two bumps on one branch
leave the intermediate version permanently unreleased, since only what
lands on `main` is tagged.

Step 13's `content-bump` check diffs tracked paths, so an addition still
untracked at the time it runs is invisible to it. It becomes visible the
moment the file is committed, and CI sees it on the push. Bump from step
2's union, not from what the validator can currently see.

Compare each touched package's manifest `version` at `$BASE` against the
working tree. Read the field, never the surrounding lines — a package
entry puts `version` several keys below its own name, so a fixed-context
`grep` prints the description and misses the number:

```sh
git show "$BASE:packages.manifest.json" | python3 -c \
  'import json,sys; p=json.load(sys.stdin)["packages"]; print({k: p[k]["version"] for k in sys.argv[1:]})' \
  <pkg1> <pkg2>
python3 -c \
  'import json,sys; p=json.load(open("packages.manifest.json"))["packages"]; print({k: p[k]["version"] for k in sys.argv[1:]})' \
  <pkg1> <pkg2>
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

`--patch` for a fix or a maintenance pass. `--minor` for a new
capability, and `--minor` for a breaking change too — during v1
incubation a break ships as one minor bump rather than a major, per the
version policy in `docs/appendix-contributing.md`. Those two sizes are
the whole set. Report which one was picked and why.

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

Run this only when the manifest diff against `$BASE` touches one of the
blocks that decide what gets installed: `require` (third-party),
`requires`/`requiresDev` (sibling keys), `requireDevExtra`,
`requireDevOverride`, or `suggest`. Refresh that package's lock with a
**scoped** update naming every current dependency, never a bare one:

```sh
docker run --rm -e GIT_CONFIG_COUNT=1 -e GIT_CONFIG_KEY_0=safe.directory -e GIT_CONFIG_VALUE_0='*' \
  -v "$PWD":/app -w /app/packages/<pkg> composer:2 \
  update <dep1> <dep2> ... --with-all-dependencies
docker run --rm -e GIT_CONFIG_COUNT=1 -e GIT_CONFIG_KEY_0=safe.directory -e GIT_CONFIG_VALUE_0='*' \
  -v "$PWD":/app -w /app/packages/<pkg> composer:2 \
  validate --strict
```

The `GIT_CONFIG_*` triple is what `ci.yml` passes every Composer step:
git refuses a checkout whose owner differs from the container's user.
From a worktree, add `-v "$COMMON":"$COMMON"` to both commands — see the
worktree mount in step 1.

## 5b. Workflow changes only

A workflow is YAML nothing else here parses, and a broken expression or
a mistyped `needs:` only shows up once it runs on `main`. Pin the linter
version, so a new release cannot change the verdict under you:

```sh
docker run --rm -v "$PWD":/repo -w /repo rhysd/actionlint:1.7.7 -color
```

It embeds shellcheck for every `run:` block across every workflow, so a
non-zero exit can come from a script this branch never touched. Read the
findings and own the ones on lines this branch changed.

## 6. Lint every touched PHP file that still exists

The set step 2 derives, read NUL-delimited so a path holding a space or
a newline survives being collected: `git diff -z` against `$BASE` for
tracked changes, `git ls-files --others -z` for untracked ones, with
`--exclude-standard` keeping `vendor/` out. The whole set then goes
through one container.

```sh
FILES=()
while IFS= read -r -d '' f; do
  case "$f" in *.php) [ -f "$f" ] && FILES+=("./$f");; esac
done < <({ git diff --no-renames --name-only -z "$BASE"
           git ls-files --others --exclude-standard -z; })

[ "${#FILES[@]}" -eq 0 ] || docker run --rm -v "$PWD":/app -w /app php:8.4-cli-alpine \
  sh -c 'for f in "$@"; do php -l "$f" || exit 1; done' sh "${FILES[@]}"
```

Four properties the shape depends on:

- `[ -f "$f" ]` drops a path the branch deleted. `php -l` answers "No
  such file" for one, which reads as a syntax failure for a legitimate
  deletion.
- The `./` prefix makes a name starting with `-` a path rather than an
  option.
- The names travel as arguments — `"${FILES[@]}"` arriving as `sh -c`'s
  own `"$@"`, with the `sh` after the script standing in for `$0` — so
  nothing re-splits one on the way.
- An empty array starts no container. `tools/` is in scope, and a branch
  touching no PHP is a clean skip.

The first syntax error prints and ends the run.

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

Each package installs into its own `vendor/`, and steps 8-10 all run out
of it. A package with none yet — a fresh clone, a package this branch is
the first to touch — needs one install before any of them:

```sh
docker run --rm -e GIT_CONFIG_COUNT=1 -e GIT_CONFIG_KEY_0=safe.directory -e GIT_CONFIG_VALUE_0='*' \
  -v "$PWD":/app -w /app/packages/<pkg> composer:2 install --no-interaction --prefer-dist
```

From a worktree, that install takes `-v "$COMMON":"$COMMON"` too — see
step 1. `roadrunner-adapter` additionally takes
`--ignore-platform-req=ext-sockets`, a hard `require` of
`spiral/roadrunner-worker` that nothing in its committed suite, PHPStan
or Psalm ever loads.

Then the suite:

```sh
docker run --rm -v "$PWD":/app -w /app/packages/<pkg> php:8.4-cli-alpine \
  php vendor/bin/phpunit
```

Three directories need more than that image ships, exactly as `ci.yml`'s
own matrix records:

- **`persistence`** — its native Postgres driver refuses to construct
  without `ext-sockets`, and the suite constructs one, so the plain form
  above errors rather than skipping:

  ```sh
  docker run --rm -v "$PWD":/app -w /app/packages/persistence php:8.4-cli-alpine sh -c \
    "apk add --no-cache --virtual .build \$PHPIZE_DEPS linux-headers >/dev/null && docker-php-ext-install sockets >/dev/null && php vendor/bin/phpunit"
  ```

- **`mcp-docs`** — its suite drives `setup.sh`, a bash script, and
  Alpine ships none:

  ```sh
  docker run --rm -v "$PWD":/app -w /app/packages/mcp-docs php:8.4-cli-alpine sh -c \
    "apk add --no-cache bash >/dev/null && php vendor/bin/phpunit"
  ```

- **`tools/`** — not a manifest package and never in the affected set, so
  run it whenever the diff touches `tools/`. It has its own
  `composer.json`, `phpstan.neon` and `psalm.xml`, so it installs its own
  `vendor/` and steps 9 and 10 apply to it as much as to any package. Its
  suite drives real git, `git subtree` included:

  ```sh
  docker run --rm -e GIT_CONFIG_COUNT=1 -e GIT_CONFIG_KEY_0=safe.directory -e GIT_CONFIG_VALUE_0='*' \
    -v "$PWD":/app -w /app/tools composer:2 install --no-interaction --prefer-dist
  docker run --rm -v "$PWD":/app -w /app/tools php:8.4-cli-alpine sh -c \
    "apk add --no-cache git git-subtree >/dev/null 2>&1 && git config --global safe.directory '*' && php vendor/bin/phpunit"
  docker run --rm -v "$PWD":/app -w /app/tools php:8.4-cli-alpine \
    php vendor/bin/phpstan analyse --no-progress --memory-limit=512M
  docker run --rm -v "$PWD":/app -w /app/tools php:8.4-cli-alpine \
    php vendor/bin/psalm --no-progress
  docker run --rm -v "$PWD":/app -w /app/tools php:8.4-cli-alpine \
    php vendor/bin/psalm --no-progress --taint-analysis
  ```

  From a worktree the install and the suite each take
  `-v "$COMMON":"$COMMON"` — see step 1.

Stop at the first suite that fails or errors. `SKIPPED` — a real-backend
test with no live service configured — is expected, not a failure.

## 9. PHPStan every affected package

```sh
docker run --rm -v "$PWD":/app -w /app/packages/<pkg> php:8.4-cli-alpine \
  php vendor/bin/phpstan analyse --no-progress --memory-limit=512M
```

## 10. Psalm every affected package

Every package carries a `psalm.xml`, `tools/` included. Both passes, as
`ci.yml` runs them: `--taint-analysis` reports taint issues only and
suppresses every ordinary issue type, so the plain run is what catches
those.

```sh
docker run --rm -v "$PWD":/app -w /app/packages/<pkg> php:8.4-cli-alpine \
  php vendor/bin/psalm --no-progress
docker run --rm -v "$PWD":/app -w /app/packages/<pkg> php:8.4-cli-alpine \
  php vendor/bin/psalm --no-progress --taint-analysis
```

## 11. Docs build, only if docs touched

Must report `build succeeded`.

```sh
docker run --rm -v "$PWD/docs":/app -w /app python:3.12-slim \
  bash -c "pip install -q -r requirements.txt && sphinx-build -M html . _build -W --keep-going"
```

## 12. Changed code examples

```sh
git diff "$BASE" -- docs .claude README.md tools/README.md \
  'packages/*/README.md' '.env.example' 'packages/*/.env.example'
```

`.env.example` is in scope because it is an example that runs: a
consumer copies it to `.env` and the application reads every key in it,
so a stale key name or an unsupported value is a broken example, not a
comment.

Read the hunks. A changed fence line is the obvious case, but most edits
land *inside* a block and never touch a fence, so grepping for fence
lines alone reports nothing on exactly the diffs that matter. Empty
output skips this step.

Otherwise check each changed example, and check it against what it
claims to be. A shell example is run, not read: a `docker run` line that
names a wrong flag, image or path fails the moment it is invoked and
passes review every time it is not. A PHP example is executed where it
stands alone and `php -l` where it does not — a fragment is not required
to run on its own, but every package and PSR symbol it names must
resolve, and a signature it shows must be the one the source declares.
An application-owned placeholder the surrounding prose identifies as the
reader's to supply stays contextual; an unresolvable Kinetis symbol
never does. A config key is checked against the `Config` read that
consumes it.

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
between them they enforce the whole of step 3. An empty or unreadable
`--base` fails the run rather than skipping them, so a pass on those two
is a real one. `Skipped` means the run found no earlier commit to
compare against at all, which `$BASE` rules out — read it as a broken
invocation rather than a pass.

From a worktree, add the step 1 mount, or every history read fails with
`Could not read HEAD`:

```sh
docker run --rm -e GIT_CONFIG_COUNT=1 -e GIT_CONFIG_KEY_0=safe.directory -e GIT_CONFIG_VALUE_0='*' \
  -v "$PWD":/app -v "$COMMON":"$COMMON" -w /app php:8.4-cli-alpine sh -c \
  "apk add --no-cache git >/dev/null 2>&1 && php tools/validate-manifest.php --base=$BASE"
```

## 14. Writing-rule and duplication pass

These are the rules, in full, for every comment, docblock, docs page,
README and commit message this branch adds. They hold on their own; a
repository `CLAUDE.md`, where one is present, adds local guidance on top
and replaces none of them.

1. **Current state only.** No was/now, no "previously", no "this was
   tried and reverted", no "turned out to be". A reader needs how it
   works and why it must stay that way. History lives in git.
2. **No filler intensifiers** — "genuinely", "for real", "not assumed",
   "confirmed directly", "deliberately" as decoration. A fact that needs
   emphasis to be believed is a fact stated badly.
3. **No test counts or tool-status lines.** A count of tests, or a line
   asserting that static analysis passes, is stale on the next commit,
   and CI enforces it anyway.
4. **State a normative claim once**, in the authoritative place, and
   reference it elsewhere, so two copies cannot drift apart.
5. **No other-framework comparisons** unless the comparison is itself
   the point.
6. **State decisions directly.** No apologising for them, defending
   them, hedging them or narrating how they were reached.
7. **Validate every changed docs and README example.** Execute or lint a
   standalone one. Check a contextual fragment against the referenced
   API, and identify application-owned placeholder types explicitly —
   step 12.
8. **Commit messages: short and factual**, about two sentences on what
   changed. Never echo the instruction back as the message.
9. **No AI or agent attribution** anywhere in a commit or PR — step 16
   checks the history for it.

Read the branch's own diff against `$BASE`, not the whole repo, and read
all of it — no pathspec. The rules cover every changed comment and
docblock, and a workflow's `run:` comments, a `.semgrepignore` reason and
`sonar-project.properties`' inline notes are prose like any other. A
binary file contributes one `Binary files ... differ` line and nothing
that matches.

Two greps cover the shapes that recur; the rest need a read.

**Rules 1, 5 and 6 — narration, hedging, other-framework mentions** on
added lines:

```sh
git diff "$BASE" | grep '^+' | grep -viE '^\+\+\+' | grep -niE \
  "previously|used to be|no longer opens|was reverted|the previous |reversed from|renamed from|deprecated in favor|used to (drop|lose|close|skip|throw|return|require|need)|honest caveat|to be fair|admittedly|unfortunately|sadly|Laravel|Symfony's own|Django|CodeIgniter|CakePHP"
```

**Rules 2 and 3 — filler intensifiers and tool-status lines:**

```sh
git diff "$BASE" | grep '^+' | grep -viE '^\+\+\+' | grep -niE \
  "genuinely|deliberately|for real|not assumed|confirmed directly|truly|actually verified|PHPStan level [0-9]|[0-9]+ (new )?tests|clean$"
```

Both patterns over- and under-fire, so every hit needs a read: "no
longer opens" can be a present-tense hypothetical rather than history,
and "deliberately not covered" can be the distinction between a decision
and an oversight rather than decoration. They are starting points, not
verdicts.

**The commit messages.** The diff holds none of them, so read the
branch's own log against the same rules — rule 8's two sentences, and
rules 1, 2 and 3, which a message breaks as easily as a docs page does:

```sh
git log --format='%h%n%B%n--' "$BASE..HEAD"
```

Step 16 reads the same range for agent attribution only; this is the
prose pass over it.

**Rule 4 — duplicated normative claims.** A new ordering guarantee,
numeric default, "throws when" condition or composition claim that
already exists in a class docblock, a docs page, an appendix entry or a
README references the authoritative copy instead of adding a second one
that can drift. No grep for this one: recognize the shape of a
behavioral claim, then check `docs/appendix.md`,
`docs/appendix-packages.md`, and the relevant page or README.

## 15. Final read of the diff

```sh
git diff --stat "$BASE"
git diff "$BASE"
```

The whole branch against `$BASE`, not a bare `git diff` — that one shows
unstaged work only, so everything already committed on the branch, which
is most of what is about to be pushed, goes unread.

That still leaves untracked files, which no diff reaches. A stray dump
or a pasted credential is exactly the kind of file that is untracked.
List them first, with the size and kind of each:

```sh
git ls-files --others --exclude-standard -z |
  while IFS= read -r -d '' f; do
    if [ ! -s "$f" ]; then kind=empty
    elif LC_ALL=C grep -qI . "$f"; then kind=text
    else kind=binary; fi
    printf '%s\t%s bytes\t%s\n' "$kind" "$(wc -c < "$f" | tr -d ' ')" "$f"
  done
```

`-z` with `read -d ''` survives a path holding a space or a newline, and
an empty list prints nothing rather than looping on one blank name. The
size test comes first because `grep` finds no line in a zero-byte file
and would otherwise call it binary. `--exclude-standard` honours
`.gitignore`, so `vendor/` and `_build/` stay out — a path that *should*
be ignored and is not shows up here, which is the other thing this step
catches.

Then read every `text` entry **to its end**, in chunks sized to the byte
count the listing gave, not a fixed prefix — a credential pasted at the
bottom of a long file is the case a prefix misses:

```sh
sed -n '1,400p' "<path>"   # then 401,800p, and on, until the whole file is read
```

A `binary` entry is identified rather than dumped: `file "<path>"` names
it, and one that does not belong is opened with a tool that suits it.

Across both halves, look for anything unintended: a stray debug file, a
secret, a vendored artifact, a file that does not belong to this change.
Report the path and what kind of thing it is — a secret goes in the
report as "an API token in `<path>`", never as its value, and a file that
holds one is deleted or ignored before the push rather than quoted.

## 16. Human-only history

Every commit this branch adds must read as the user's own work: a human
author and a human committer, and no agent attribution in a message body
or trailer — `Co-Authored-By:` an agent, `Generated with`, a session
link.

Read the identities. They are a short list, and reading it is the only
thing that answers whether a person made each commit:

```sh
git log --format='%an <%ae>%n%cn <%ce>' "$BASE..HEAD" | sort -u
```

Name every entry in the report and say it is a person. A bot or service
account — `renovate[bot]`, `dependabot[bot]`, `github-actions[bot]`, an
agent's own name — fails this step while passing the grep below, which
only knows the names it lists. That is why the list is read rather than
pattern-matched.

Then the message bodies, for attribution a human identity still carries:

```sh
git log --format='%H%n%an <%ae>%n%cn <%ce>%n%B%n--' "$BASE..HEAD" | grep -niE \
  "claude|anthropic|copilot|codex|cursor|openai|GPT|AI[- ]generated|Generated with|Co-Authored-By"
```

`GPT` is matched plainly, with no `\b` around it: word boundaries are
undefined in POSIX ERE and behave differently across the greps this runs
on. It costs the occasional hit inside a longer word, which is free —
every hit here is read before it is acted on.

A list of people and no grep output is the pass. A hit on a commit's own
metadata is fixed with `git commit --amend --reset-author` or an
interactive rebase before the push, never after. A hit inside a
documentation diff quoted in a message is not one of these — read it.

Uncommitted work carries none of this yet: an author and a message exist
only once a commit does, so a clean tree with staged or unstaged changes
has nothing here to check. Say the range was empty rather than reporting
a pass, and run this again after the commit exists.

## 17. Report and stop

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
