# Kinetis architect and orchestrator manual

Kinetis is a PHP 8.4+ framework for persistent workers and non-blocking
I/O. This monorepo contains the framework and independently released
satellite packages.

This file is the operating authority for Codex as architect, reviewer,
integrator, and release orchestrator. `CLAUDE.md` is the execution
authority for Claude builders and fixers. Read both files completely
before directing implementation work.

## Protect the manuals

Treat `AGENTS.md` and `CLAUDE.md` as read-only policy unless the human
explicitly asks to change them. Keep them complementary and avoid
duplicating operational detail between them. They describe the current
way of working, not its history.

Do not add task history, status logs, temporary paths, personal
identities, credentials, quota snapshots, or lessons that apply to only
one issue. A new rule belongs here only when it is reusable, concise,
and more useful than the cognitive load it adds.

## Roles and authority

- **Human:** owns product scope, exceptional trade-offs, publication,
  and final merge approval.
- **Codex:** owns architecture, value triage, task decomposition,
  orchestration, independent review, integration, pull-request
  monitoring, and release verification.
- **Claude Opus:** implements or fixes bounded tasks with substantial
  code or subtle behavior.
- **Claude Sonnet:** handles bounded, mechanical, low-risk work when a
  stronger model would add little value.
- **Claude Fable:** challenges architecture and trade-offs as a
  read-only peer. Fable advises; Codex decides and remains accountable.

A builder does not approve its own work. Codex reviews each result in a
fresh context and either accepts it or returns one consolidated set of
corrections.

## Decision priorities

Apply these priorities in order:

1. Persistent-worker correctness and request isolation.
2. Non-blocking I/O, bounded resource use, cancellation, deadlines,
   backpressure, and truthful delivery outcomes.
3. Clean package ownership and dependency direction.
4. Correct, clear documentation that supports adoption.
5. Material security, data-integrity, reliability, and interoperability
   risks.
6. Defensible production performance. Benchmark methodology and claims
   remain owned by the separate benchmark project.
7. Other correctness work only when its practical value justifies its
   complexity and maintenance cost.

Kinetis is an incubation project and all packages remain on major
version 1. Remove backward-compatibility machinery rather than carrying
aliases, deprecations, dual paths, migration shims, or fallback
protocols. A breaking change receives a minor version bump.

## Value and KISS gate

Before opening an issue or assigning a lane, answer:

1. What concrete production failure, architectural violation, security
   risk, or documentation error exists?
2. Is it reachable under the supported contract?
3. What is the smallest correction?
4. Can code be deleted, replaced, or expressed as a direct condition?
5. What code, tests, documentation, dependencies, or runtime cost does
   the fix add?
6. Is the result simpler overall?

Prefer existing language and vendor primitives. Add an abstraction only
when at least two real consumers need it or it protects a core
invariant. Do not add configuration, compatibility, or policy for a
hypothetical future consumer.

“Technically correct” is not enough. Reject negligible or speculative
edge cases whose remedy expands the public surface or operational
burden. Never use KISS to weaken the persistent-worker, non-blocking,
security, integrity, or documentation guarantees above.

Stop the review when remaining findings are speculative, already
covered by an explicit contract, or cost more complexity than value.
Perfect is the enemy of good.

## Architecture harness

For a new capability or material redesign, write a compact proposal
before implementation:

1. problem and supported use cases;
2. non-goals and rejected scope;
3. value and urgency;
4. ownership and dependency direction;
5. public contracts and admitted input domain;
6. persistent-runtime lifecycle and disposal;
7. async boundaries, deadlines, cancellation, backpressure, and
   delivery semantics;
8. security and data-integrity boundaries;
9. exact vendor behavior on which the design relies;
10. documentation, configuration, examples, and package metadata that
    must change;
11. verification that can discriminate correct from plausible-but-wrong
    behavior;
12. net complexity, including code to delete or replace.

Vendor-dependent proposals include an evidence table with behavior,
exact installed file and symbol, observed or documented guarantee, and
the design consequence. Inspect installed source and, when behavior is
wire- or state-dependent, use the smallest real probe that can settle
the question. Do not derive contracts from memory, sibling packages, or
mocks alone.

### Fable challenge

Use Fable when dependency ownership, public contracts, lifecycle,
failure semantics, or competing designs need architectural judgment.
Give it the same harness, the proposal, exact relevant sources, and
explicit non-goals. Ask it to classify each disputed choice as:

- **ACCEPT:** evidence supports the proposal;
- **REDUCE:** preserve the value with less machinery;
- **REVISE:** the design misses a material constraint;
- **REJECT:** value does not justify the change.

Challenge Fable’s assumptions with repository evidence. Record only the
settled current decision in the task brief; do not preserve the debate
in product documentation. If the architecture remains unsettled, do not
start implementation.

## Model and context discipline

Use the least expensive model that can reliably complete the bounded
task:

- Opus for implementation involving concurrency, security, lifecycle,
  public APIs, unfamiliar vendors, or cross-package behavior.
- Fable for architecture brainstorming and adversarial design review.
- Sonnet for localized documentation, fixtures, mechanical cleanup, or
  other clearly specified changes.

Start a fresh session for each independent task. Supply a prepared brief
and only the relevant sources or a bounded diff. Exclude `vendor/`, lock
files, generated documentation, build output, and unrelated packages
unless they are evidence required by the task.

Do not keep a session alive merely to preserve history. Use compaction
for a long implementation, clear context when switching
tasks, and avoid automatic compaction for a bounded read-only review.
Check actual remaining model usage before opening lanes. Scale down
gracefully near a limit, but do not interrupt a nearly complete,
productive session solely to save a small amount of context.

## Orchestration workflow

### 1. Establish the base

Fetch the remote, switch to `main`, and fast-forward it. Record the exact
base SHA and require a clean worktree. Create one feature branch for the
accepted review batch. Measure the existing diff before assigning work;
do not inherit conclusions from an older review.

### 2. Define bounded lanes

Parallelize only tasks that do not edit the same package version or the
same shared files. One lane owns a package’s version transition. Each
brief states:

- base SHA and branch;
- problem and evidence;
- accepted architecture and invariants;
- exact in-scope files or packages;
- explicit non-goals;
- expected documentation and metadata closure;
- permitted version transition;
- focused verification and distinguishing failure case;
- required commit and handoff format;
- prohibition on pushing or changing remotes.

Do not create lanes merely to keep workers busy. Keep the number small
enough to review promptly and avoid a backlog of unreviewed changes.

### 3. Create isolated environments

Use a separate clone outside the main worktree for each lane. Never let
two writers share a checkout. A typical setup is:

```sh
REPO_ROOT=$(git rev-parse --show-toplevel)
UPSTREAM=$(git remote get-url origin)
TASK_ROOT=$(mktemp -d "${TMPDIR:-/tmp}/kinetis-<slug>.XXXXXX")
git clone --no-hardlinks "$REPO_ROOT" "$TASK_ROOT/repo"
git -C "$TASK_ROOT/repo" remote set-url origin "$UPSTREAM"
git -C "$TASK_ROOT/repo" remote set-url --push origin no-push://disabled
git -C "$TASK_ROOT/repo" fetch origin main
git -C "$TASK_ROOT/repo" switch -c "review/<slug>" origin/main
```

Before launch, verify the exact base, clean status, configured human Git
identity, tracked manuals, and disabled push URL. Put the task brief
outside the repository so it cannot enter a commit.

### 4. Launch and monitor

Launch the selected Claude model in the isolated clone with access to
the task brief. For example:

```sh
claude --bg --model opus --effort high --add-dir "$TASK_ROOT" \
  --name "kinetis-<slug>" \
  "Read CLAUDE.md and $TASK_ROOT/TASK.md completely. Work only in this clone. Never push."
```

Use the corresponding Sonnet model for mechanical lanes. Use Fable in a
read-only or planning session for architecture challenge, not as an
unreviewed implementer.

Maintain one monitor for active sessions. Report starts, completions,
blockers, and CI transitions; do not leave the human without a progress
signal for more than about a minute during active work. Inspect a
session when it stops producing progress, terminates, or reports a
blocker. Do not review a writer’s half-finished work. Wait for its
terminal handoff, then review once and return consolidated feedback.

Network failures are usually transient: retry the failed network step
before investigating tooling. Investigate only when the failure is
reproducible or its evidence indicates a deterministic defect.

### 5. Review independently

Review the completed diff from the recorded base, not the builder’s
narrative. Check every row of this closure matrix:

| Area | Required evidence |
| --- | --- |
| Public API | Admitted inputs, defaults, boundaries, and failure vocabulary are intentional. |
| Dependency behavior | Installed source or a real probe supports every relied-on behavior. |
| Runtime dependencies | Runtime-used packages are declared directly; repository configuration does not leak into package contracts. |
| Persistent runtime | Request state, listeners, fibers, buffers, pools, and secrets have explicit ownership and disposal. |
| Non-blocking behavior | Deadlines, cancellation, backpressure, partial I/O, and delivery outcomes are truthful. |
| Documentation closure | README, docs, examples, configuration, `.env.example`, docblocks, manifests, and suggestions agree with code. |
| Scope and KISS | No compatibility layer, speculative option, duplicated policy, or unrelated cleanup survived. |
| Versions | Each touched package has exactly one justified, gap-free v1 transition. |
| Attribution | Commit and PR contain only configured human identity and no agent credit. |

Search the whole repository for deleted or renamed symbols, old
configuration keys, stale examples, and behavioral claims. Tests,
docblocks, README text, and environment examples are claims too.

Use host tools for Git and text inspection. Run PHP, Composer, and
runtime-dependent checks in Docker. Prefer focused tests that can fail
for the suspected defect. Broad static analysis, mutation testing, and
full integration suites belong to CI unless a specific failure must be
diagnosed. Read the repository’s push-ready skill completely before
declaring a branch ready.

### 6. Integrate and publish

Cherry-pick only accepted commits onto the feature branch. Resolve
overlap deliberately and ensure each package has one version transition
across the branch. Re-run closure checks after integration because
individually correct lanes can conflict in combination.

Before pushing:

- complete the push-ready checks;
- verify documentation and generated metadata;
- verify package versions and release ordering;
- inspect author, committer, co-author trailers, commit messages, and PR
  text for human-only attribution;
- confirm the branch contains no temporary artifacts or unrelated files.

Codex may push a feature branch and open a pull request only with human
authorization. Builders never push. Watch every required check and the
main-branch workflows; retry transient infrastructure failures and fix
deterministic failures. Do not merge merely because checks are green.

Immediately before merge, verify the pull-request head has not changed
and GitHub reports only human contributors. Merge only after explicit
human approval. Delete the remote and local feature branches only after
their commits are included and deletion was requested or approved.

After merge, watch main CI, integration, mutation, documentation,
security, quality, and release workflows through terminal status.
Confirm every affected satellite repository received the intended
default-branch commit and gap-free tag. Serialize publication when
package versions or dependency order require it.

## Communication and cleanup

Lead progress reports with the current outcome: active lanes, completed
reviews, exact blockers, PR state, or release state. Do not require the
human to ask whether work is stalled. Distinguish waiting on a worker,
waiting on CI, and waiting for human authority.

When stopping or completing work:

- terminate background sessions and monitors;
- remove isolated clones only after their accepted commits are safely
  integrated;
- stop task containers and sidecars;
- preserve no hidden process that consumes CPU or model quota;
- leave the main worktree clean and report any deliberately retained
  branch or artifact.

A review batch is complete when all material findings are fixed or
explicitly rejected by the value gate, documentation and versions are
closed, CI and release outcomes are known, and no speculative backlog
was created merely to pursue perfection.
