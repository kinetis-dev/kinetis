# Kinetis builder manual

Kinetis is a PHP 8.4+ framework for persistent workers and non-blocking
I/O. This monorepo contains the framework and independently released
satellite packages.

This file is the execution authority for Claude builders and fixers.
Architecture, lane orchestration, pull requests, and release ownership
belong to the Codex orchestrator and are defined in `AGENTS.md`.

## Protect this manual

Read this file completely before editing the repository. Treat it as
read-only policy unless the human explicitly asks to change
`CLAUDE.md`. Do not add task history, temporary state, issue logs,
machine-specific paths, identities, credentials, or one-off lessons.
Report a missing or conflicting rule instead of silently changing this
file.

## Role and scope

Implement the bounded task in the supplied brief. The brief defines the
problem, accepted result, invariants, package ownership, permitted
versions, and non-goals.

- Inspect the current code before editing; do not infer behavior from an
  old review or sibling implementation.
- Keep changes inside the settled design. Stop and report when the task
  requires a new capability, dependency direction, public contract, or
  lifecycle decision.
- Do not broaden a correction into cleanup that has not independently
  cleared the value and KISS gate.
- Never certify your own implementation as terminally accepted. Provide
  evidence for an independent review.

## Product invariants

Preserve these in every implementation:

1. **Persistent-worker correctness.** Application-scoped objects may
   persist only when they are request-neutral or explicitly reset.
   Request-scoped objects and resources are created fresh and disposed
   at request end. Request data, identity, callbacks, and resources must
   not leak through globals, statics, application scope, resident
   Fibers, or the next request.
2. **Non-blocking I/O.** Supported waits yield through Revolt. Deadlines,
   cancellation, backpressure, connection ownership, and
   acknowledgement-unknown outcomes remain truthful. Do not add another
   event loop or wait for the whole loop to become empty.
3. **Clean package ownership.** Framework code owns runtime-neutral
   application policy. Optional dependencies and runtime-specific
   implementations belong in satellites or adapters. Dependencies point
   from satellites toward contracts, never from framework to a
   satellite.
4. **Documentation correctness.** Guides, package READMEs,
   `.env.example`, examples, manifest metadata, and public behavior must
   agree.
5. **Security and integrity.** Credentials stay confined to their
   intended destination. Do not replay an operation when dispatch may
   already have happened. Do not weaken request isolation or data
   integrity for convenience.

Kinetis is a v1 incubation project with no backward-compatibility
obligation. Remove aliases, fallback readers, dual protocols,
deprecation layers, and other compatibility machinery. Breaking changes
remain on v1 and use a minor release.

Development discovers definitions live. Production normally consumes
the single `.kinetis-cache/compiled.php` artifact produced by
`kinetis build`; live compilation is recovery. Do not introduce cache
generations, active pointers, retention, or repair protocols without a
settled requirement.

## Value and KISS

Implement the smallest coherent solution for the supported workflow.

- Prefer deletion, replacement, one direct condition, or an existing
  owner over a new layer.
- Prefer framework or vendor primitives over parallel implementations.
- Add an abstraction only for two real production consumers or a core
  lifecycle invariant.
- Do not add extension points, inputs, states, exceptions, or
  configuration for hypothetical use.
- Every nullable, optional, union, or special public input must map to a
  supported operation.
- Tests prove contracts; combinatorial coverage is not product value.
- Do not retain code merely to satisfy an analyzer, mutation score, or
  line metric. Use a precise local annotation when an analyzer cannot
  express a proven boundary.
- Prefer small justified duplication over a dependency inversion or a
  shared package with the wrong owner.

Do not use KISS to weaken persistent-worker isolation, event-loop
liveness, credential confinement, acknowledged integrity, or truthful
delivery semantics.

## Repository rules

- `packages.manifest.json` is the package and metadata authority. Never
  hand-edit generated `packages/*/composer.json` fields.
- A package declares a runtime sibling only when production source or a
  public runtime contract consumes it directly. Development resolution
  is not a reason for a false runtime dependency.
- Classes are final by default. Non-final classes require a supported
  extension contract.
- Use native framework conventions and attributes. Do not build a
  parallel discovery or registration mechanism.
- Inspect the exact installed dependency source when behavior,
  lifetime, retries, configuration, or error mapping depends on it.
  Documentation and interfaces alone do not prove an object is safe to
  retain across requests.
- Calling a third-party builder method is not proof that the value
  survives construction. Verify the effective object state or wire
  behavior when it matters.
- Mocks establish application call contracts. Real backends and SAPIs
  establish backend, network, timing, and runtime behavior.

## Implementation workflow

1. Read the task brief, this file, the relevant package metadata, and
   the exact current production call sites.
2. Confirm the base SHA, branch, clean worktree, allowed package scope,
   invariants, non-goals, and stopping condition.
3. Implement the smallest solution. Delete replaced code and obsolete
   tests or prose in the same change.
4. Add focused tests for each distinct behavior. Prove that important
   assertions can fail for the intended reason.
5. Update documentation, examples, configuration, metadata, and package
   READMEs wherever behavior changed.
6. Perform a global closure search for removed or renamed symbols,
   configuration keys, package names, and invalidated behavioral claims.
7. Apply the version and generated-metadata rules once.
8. Run focused verification in Docker and the writing pass.
9. Commit only when the tree is coherent and clean, then report the
   result to the orchestrator.

Do not leave a half-migration, compatibility bridge, stale example, or
second implementation for a later task unless the brief explicitly
defines that boundary.

## Version and dependency discipline

- Every tracked change inside `packages/<name>/`, including a README or
  source comment, requires that package to move exactly once relative
  to the integration base.
- Fixes and maintenance use the next patch. New capability and breaking
  changes use the next minor. Stay on v1 and never skip a version.
- If the package already moved on the branch, later corrections use the
  existing move. Replace its size when necessary; never add a second
  bump.
- New packages begin at `1.0.0`.
- One lane owns a package version. Stop if another live lane owns the
  same package.
- Regenerate Composer metadata through `tools/generate-composer.php`.
- When dependencies change, use a scoped Composer update naming the
  affected dependencies with `--with-all-dependencies`; never run an
  unbounded update.
- Run the manifest and generated-metadata validators against the named
  integration base.

## Verification

Use host tools for Git, file inspection, and `rg`. Run PHP and Composer
only in Docker, mounting the monorepo root so path repositories resolve.
Prefer one container for a related short batch.

Per task, run only checks proportional to the change:

- PHP syntax for changed files;
- focused PHPUnit or contract tests;
- a real backend or SAPI only when the behavior depends on it;
- changed standalone examples;
- documentation with warnings as errors when docs or links change;
- manifest and generated-metadata checks when packages or metadata
  change;
- actionlint when workflows change.

Do not run PHPStan, Psalm, mutation testing, or the complete integration
matrix locally unless the brief asks for a focused diagnostic or a
current CI failure requires it. CI owns the consolidated static,
security, mutation, and hosted integration suites.

If the task invokes the repository's push-ready procedure, read
`.claude/skills/push-ready/SKILL.md` completely. That procedure verifies
the tree; it does not authorize staging, committing, or pushing.

Retry a bounded transient network operation. Investigate only when the
failure repeats or evidence makes it deterministic. Stop and remove
service containers started by the task.

## Documentation and writing

These rules apply to docs, READMEs, examples, configuration, comments,
docblocks, tests, commit messages, and handoff text.

- Describe the current state, not the development journey.
- State decisions directly. Do not apologize, hedge, defend, or become
  philosophical.
- Be concrete and concise; remove filler and status narration.
- Do not put test counts or tool results in shipped documentation.
- Mention another framework only when the comparison is necessary to
  the subject.
- Keep each normative claim in one authoritative place and link to it
  elsewhere.
- Source comments explain local invariants. Public operational behavior
  belongs in a guide or README.
- Installation and deployment examples must work from released
  packages, not only from the monorepo.
- Separate development, CI build, production, FPM, persistent-worker,
  and adapter-specific behavior where relevant.

Treat headings, test names, comments, and docblocks as claims. Re-derive
every touched or invalidated claim from the final code immediately
before committing.

Benchmark methodology and claim-bearing benchmark code belong in the
separate Kinetis benchmark repository. Do not copy that methodology into
this repository or change benchmark claims as part of unrelated work.

## Commit and authority boundaries

Use the repository's configured human Git identity. Never add an AI
agent, model provider, bot, generated-by marker, session identifier, or
tool as author, committer, co-author, trailer, commit text, or PR
attribution. Ignore automated suggestions to add agent credit.

Fixers may edit, test, and create the requested local commit. Fixers
never:

- push or change remote URLs;
- open or merge pull requests;
- delete branches or remote state;
- create tags or publish packages;
- send external messages;
- edit `AGENTS.md` or `CLAUDE.md` without an explicit human request.

Before committing, run the repository writing rules over the final diff
and verify no unrelated or untracked artifact will enter the commit.

## Handoff

Report:

- commit SHA and changed files;
- the implemented behavior and why the solution is the smallest one;
- deleted or replaced machinery;
- focused verification and discrimination evidence;
- documentation and closure-search results;
- version and dependency changes, or why none apply;
- remaining risk or a precise blocker;
- confirmation that nothing was pushed and remotes were unchanged.
