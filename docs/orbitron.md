# Orbitron

`kinetis/orbitron` is a development-only construction harness. It does
not contain a coding agent — you bring one — and it does not talk to a
model. What it gives an agent is four documents: a portable Kinetis
context document, this project's installed `kinetis/*` package inventory
as JSON, a deterministic verification of this project's Composer layout,
and one health-endpoint scaffold that is previewed before it is applied.

Reach them either way. Any agent that can run a shell command uses the
four commands on `vendor/bin/kinetis`; an agent that speaks MCP registers
`vendor/bin/kinetis-orbitron-mcp` and calls the same documents as tools
(see [Over MCP](#over-mcp)). Both adapt the same services, so neither can
report something the other does not. {doc}`mcp-docs` is the separate
server that hands these documentation pages to an MCP client.

## Install

```console
composer require --dev kinetis/orbitron
```

`--dev` is the supported installation. Orbitron registers one scan root
and nothing else — no bootstrap, no route, no listener, no runtime
service, no configuration key. Its MCP server is a binary a client
launches, not a registration. Application production code never depends
on it, and `composer remove --dev kinetis/orbitron` changes nothing an
application does.

## Read the context

```console
vendor/bin/kinetis orbitron:context
```

One document, rendered whole to STDOUT:

- what Orbitron is, and what it does not establish;
- links to the authoritative guides — {doc}`agent-workflow`,
  {doc}`application-recipes`, {doc}`agent-correctness` and
  {doc}`reference`;
- the workflow below;
- what each command may and may not change;
- the launcher behavior described under
  [Trust boundary](#trust-boundary);
- the installed `kinetis/*` packages and their versions.

`--format` accepts `markdown` (the default) and `json`. Both render the
same document from the same facts, so an agent that parses JSON and a
human reading Markdown see the same claims.

The document links to the guides rather than reprinting them, and it
claims nothing about an application's correctness. Static context does
not prove that a change preserves request isolation or non-blocking
I/O — {doc}`agent-correctness` is the review that does, and the
project's own test suite is the evidence.

## Read the installed versions

```console
vendor/bin/kinetis orbitron:inspect
```

JSON only: this document exists to be parsed. Omitting `--format` and
writing `--format=json` are the same invocation.

```json
{
    "schemaVersion": 1,
    "orbitronVersion": "1.0.0",
    "packages": [
        {
            "name": "kinetis/framework",
            "version": "1.11.2"
        },
        {
            "name": "kinetis/orbitron",
            "version": "1.0.0"
        }
    ]
}
```

`packages` carries every installed package under the `kinetis/` vendor,
ordered by name, one entry per name. A name Composer lists only because
an installed package *replaces* or *provides* it carries no version and
no install path, and is not reported. Install paths are read to make
that distinction and never reach the output.

`orbitronVersion` is the detected `kinetis/orbitron` package version
rather than a constant maintained beside it, so the two cannot
disagree. In the Kinetis monorepo it reads `dev-main`.

This is what {doc}`agent-workflow`'s current-main/installed-version
boundary needs: an agent reads each guide for the versions installed
here, not for whatever `main` currently holds.

## Verify the project layout

```console
vendor/bin/kinetis orbitron:verify
```

JSON only, for the same reason as `orbitron:inspect`. One deterministic
document, with a fixed key and check order:

```json
{
    "schemaVersion": 1,
    "orbitronVersion": "1.0.0",
    "status": "pass",
    "checks": [
        {
            "name": "composerManifest",
            "state": "pass",
            "code": "manifest_read"
        },
        {
            "name": "productionNamespace",
            "state": "pass",
            "code": "namespace_unique"
        },
        {
            "name": "testNamespace",
            "state": "pass",
            "code": "namespace_unique"
        }
    ],
    "namespaces": {
        "production": "App\\",
        "test": "App\\Tests\\"
    }
}
```

`schemaVersion` is `1`, and moves only when the document's shape
changes. `status` is `error` when any check failed and `pass`
otherwise — the command exits `3` in the first case. `code` is the
stable machine value to branch on: it names an outcome, and never
echoes the manifest, an exception message, or a path. `namespaces` is
reported only when the whole layout is the admitted one; a
half-recognized project reports `null` rather than one known half.

### The layout it admits

- exactly one `autoload.psr-4` prefix whose mapping is the single string
  `"src/"`;
- exactly one `autoload-dev.psr-4` prefix whose mapping is the single
  string `"tests/"`;
- each prefix a non-empty PSR-4 namespace ending in `\`.

This is the skeleton layout. Any other mapping in either map is left
alone, array-valued ones included — a project declares whatever else it
needs. Two prefixes pointing at the same fixed path, or an array-valued
mapping that reaches it, are rejected rather than guessed at.

| Check | State | Code |
|---|---|---|
| `composerManifest` | `pass` | `manifest_read` |
| `composerManifest` | `error` | `manifest_missing`, `manifest_unreadable`, `manifest_oversize`, `manifest_not_json`, `manifest_not_an_object` |
| `productionNamespace`, `testNamespace` | `pass` | `namespace_unique` |
| `productionNamespace`, `testNamespace` | `error` | `psr4_map_missing`, `path_unmapped`, `path_ambiguous`, `path_mapping_not_a_string`, `namespace_invalid` |
| `productionNamespace`, `testNamespace` | `skip` | `manifest_unusable` |

A manifest that could not be used makes both namespace checks `skip`
rather than `error`: the layout was never seen, so nothing about it is
claimed either way.

### What it proves, and what it does not

It proves one thing: that this project's Composer layout is the narrow
one Orbitron supports. That layout is a deliberate prerequisite for the
scaffolding built on it — a fixed pair of namespaces is what lets a
generated class be placed without guessing — and it is narrower than
anything Kinetis itself demands.

Discovery asks for much less. `Kinetis\Cache\NamespaceScanner` walks
every string prefix under `autoload.psr-4`, at any directory, accepting
array-valued mappings, and it never reads `autoload-dev` at all. So an
`error` here does not mean discovery is broken: a project with three
production prefixes, or with its tests under a path this rejects, still
has every route, command, tool and listener discovered as usual — see
{doc}`cli` and {doc}`caching`.

One real discovery failure is inside what the production check catches,
though. A project with no usable `autoload.psr-4` map has no root to
scan at all, so discovery finds nothing and says so through a single
`error_log()` line. That project reports `psr4_map_missing` or
`path_unmapped` here instead, in a document an agent can read.

It proves nothing else. It is not evidence about request isolation,
non-blocking I/O, security, route uniqueness, or the correctness of any
application code. {doc}`agent-correctness` is the review that addresses
those, and the project's own test suite is the evidence.

## Scaffold the health endpoint

```console
vendor/bin/kinetis orbitron:scaffold
vendor/bin/kinetis orbitron:scaffold --apply
```

JSON only, for the same reason as the two commands above. Without
`--apply` this is a preview: every precondition is read and the plan is
written, with nothing touched on disk.

```json
{
    "schemaVersion": 1,
    "orbitronVersion": "1.0.0",
    "mode": "preview",
    "status": "ready",
    "codes": [
        "scaffold_ready"
    ],
    "targets": [
        "src/Http/HealthController.php",
        "tests/Http/HealthControllerTest.php"
    ],
    "remainingFiles": []
}
```

`schemaVersion` is `1` and moves only when the document's shape changes.
`mode` is `preview` or `apply`. `status` is `ready`, `created`,
`refused` or `failed`. `codes` are the stable machine values to branch
on, in a fixed order, and they name outcomes only — never a file's
contents, an exception message, or an absolute path. `targets` is the
complete write set, always both paths and always in this order.
`remainingFiles` is empty unless a rollback could not put the project
back.

### The two files

Nothing about them is configurable. There is no scaffold name, custom
path, template, source body, saved plan or plugin registry — one
workflow, built one way:

- `src/Http/HealthController.php`, under the production namespace the
  layout check found, declaring `#[Get('/health')]` and returning
  `['status' => 'ok']`. The dispatcher encodes an array return as JSON
  with the route's status, so the response is `{"status":"ok"}` — see
  {doc}`routing-validation`.
- `tests/Http/HealthControllerTest.php`, under the test namespace,
  extending `Kinetis\Testing\ApplicationTestCase` and issuing two
  sequential `GET /health` requests against one booted application,
  asserting the same success response both times. The second request is
  the point: a persistent worker answers it from whatever the first left
  behind — see {doc}`testing`.

Neither file names Orbitron. They import the framework and its testing
API only, so `composer remove --dev kinetis/orbitron` leaves them
working.

### What must already be true

- the layout above, with both namespaces valid;
- `src`, `src/Http`, `tests` and `tests/Http` present as real
  directories, none of them a symlink, each resolving inside the project
  root;
- neither target occupied. A regular file, a directory, a symlink and a
  symlink pointing at nothing all count as occupied — `file_exists()`
  answers `false` for the last of those, and the path is taken all the
  same.

Orbitron creates no directory. A project without `src/Http` is refused,
not filled in.

| Status | Code | Meaning |
|---|---|---|
| `ready` | `scaffold_ready` | The preview holds: both files can be created. |
| `created` | `scaffold_created` | Both files exist, every byte written, flushed and closed. |
| `refused` | the layout codes above, `manifest_unusable` included | The layout is not the admitted one, reported by the same reader `orbitron:verify` uses. |
| `refused` | `directory_missing` | One of the four fixed directories is absent, or is not a directory. |
| `refused` | `directory_outside_project` | One of them resolves outside the project root. |
| `refused` | `directory_symlinked` | One of them is a symlink. |
| `refused` | `target_exists` | One of the two targets is occupied. |
| `failed` | `write_failed` with `rolled_back` | A file could not be created or written, and everything this invocation created was removed. |
| `failed` | `write_failed` with `rollback_failed` | The removal failed too. `remainingFiles` names exactly the paths that may still be there. |

### Preview, then apply

`--apply` is the only mutation request, and it does not consume the
preview that came before it. It re-reads the manifest, the four
directories, their symlink state and both targets immediately before it
writes, so a file that appeared in between is a refusal rather than an
overwrite. `--apply` carries no value: `--apply=yes` is a rejected
invocation, like an unsupported `--format`.

Each file is created with `fopen($path, 'x+b')` — a create that fails
rather than truncating whatever is already there — and every byte goes
out in a loop that treats a failed write, or one that accepts nothing,
as the end of the attempt. A flush and a close that fail are failures
too; the handle closes on every path out.

When the second file cannot be created or finished, every file this
invocation created is removed, the incomplete one included. A file that
was already there is never removed. A removal that fails is itself
reported, with exactly the project-relative paths that may remain.

### What it cannot tell you

Whether this project already routes `GET /health` somewhere else.
Orbitron reads no application source and runs no discovery, so a
conflicting route is invisible to it and the apply succeeds. The
generated test is what surfaces it: route discovery refuses two
controllers claiming one path, and the first run of the suite after the
scaffold says so — see {doc}`routing-validation`.

## The workflow

1. `vendor/bin/kinetis orbitron:context` — once per task.
2. `vendor/bin/kinetis orbitron:inspect` — the installed versions.
3. `vendor/bin/kinetis orbitron:verify` — the project layout.
4. `vendor/bin/kinetis orbitron:scaffold` — the health-endpoint plan,
   then `--apply` when the plan is what you want.
5. Route the task through {doc}`agent-workflow`, then follow the
   matching recipe in {doc}`application-recipes`.
6. Before calling the change done, work through
   {doc}`agent-correctness` and run the project's own test suite.

An MCP client runs the same steps as tool calls; see
[Over MCP](#over-mcp).

(over-mcp)=

## Over MCP

```console
vendor/bin/kinetis-orbitron-mcp
```

A stdio MCP server speaking `2025-06-18`, for a client that launches a
server as a subprocess. Register it the way that client registers any
stdio server — Claude Code:

```console
claude mcp add orbitron -- ./vendor/bin/kinetis-orbitron-mcp
```

Codex:

```console
codex mcp add orbitron -- ./vendor/bin/kinetis-orbitron-mcp
```

| Tool or resource | What it returns |
|---|---|
| `orbitron_inspect` | The `orbitron:inspect` document. Read-only. |
| `orbitron_verify` | The `orbitron:verify` document. Read-only; an error document comes back as an MCP error result still carrying the document. |
| `orbitron_scaffold_plan` | The `orbitron:scaffold` preview document. Read-only. |
| `orbitron_scaffold_apply` | The `orbitron:scaffold --apply` document, and creates the two files. |
| `kinetis://orbitron/context` | The `orbitron:context` document, as Markdown. |

```{warning}
`orbitron_scaffold_apply` writes to the project. Selecting it *is* the
mutation request: it takes no argument, and your MCP client's own
approval prompt is where a human decides. It is annotated
`destructiveHint: true` and `idempotentHint: false` — a second apply
refuses, because the targets exist by then.
```

All four tools publish a closed, empty input schema and refuse a call
that carries any argument at all. No message can name a project root, a
path, a source body, a URL, a template or a command: the root comes from
Composer's own bin proxy, exactly as it does for `vendor/bin/kinetis`,
and every name below it is a constant. The server never boots the Kinetis
application, so running it registers no route, listener or bootstrap.

Composer's installed-package inventory is process-cached, so the server
reads it once at startup. Restart it after installing or removing a
dependency; every other document is re-read on each call, and nothing
about one call survives into the next.

## Output and exit codes

Every command writes exactly one document plus a trailing newline to
STDOUT, with fixed key and list order, and no progress text.

| Exit | Meaning |
|---|---|
| `0` | The document was written; the verification reports no error, and the scaffold preview is ready or its apply completed. |
| `2` | An unsupported `--format`, a bare `--format` naming nothing, an `--apply` carrying a value, or a positional argument. STDERR names the accepted invocation; STDOUT stays empty. |
| `3` | `orbitron:verify` and `orbitron:scaffold` only: the operation completed, and the document it wrote reports an error, a refusal, or a failed write. |
| `1` | A launcher or uncaught failure, from `vendor/bin/kinetis` itself — see {doc}`cli`. |
| `70` | The command finished and disposing the request scope afterwards failed, which is the framework binary's own behavior, not Orbitron's. |

The MCP server reports the same outcomes differently: a refusal or a
failed write is an MCP result with `isError: true` still carrying the
document, and the binary itself exits `0` at end of input and `1` when a
write to stdout fails.

A command's `CommandArguments` cannot enumerate options it never reads,
so an option no command consumes is ignored rather than rejected.
Orbitron adds no second argument parser for it, and the framework is not
changed to reject one.

(trust-boundary)=

## Trust boundary

Orbitron reads three things: Composer's installed-package metadata, the
project's own `composer.json`, and — for `orbitron:scaffold` — the
existence and symlink state of four fixed directories and two fixed
paths. No other application source, no configuration, no credentials. It
opens no socket and starts no process.

It writes two files: the scaffold's fixed targets, only on
`orbitron:scaffold --apply` or `orbitron_scaffold_apply`, each through a
create that refuses an occupied path and never truncates one. That is the
whole write set. Neither a command line nor an MCP message supplies a
path to any of it — the root comes from
`Kinetis\Runtime\ProjectRoot::detect()` and every name below it is a
constant — and nothing creates a directory.

The MCP server is a local process the client launches, so that process
and your filesystem permissions are the authority boundary. It has no
network client, no shell and no credentials to reach.

The installed metadata is read through `Composer\InstalledVersions`,
which loads `vendor/composer/installed.php` itself, so the invocation
does touch a file inside `vendor/`. Orbitron reaches nothing beyond it.

The project manifest is the one fixed name `composer.json` under the
root `Kinetis\Runtime\ProjectRoot::detect()` reports, so no path a
command line supplies is ever opened. It is read through a bounded
stream read of at most 1 MiB, plus the single byte that distinguishes an
admitted manifest from an oversized one; a larger file is refused
without being read whole, and the diagnostic names the outcome rather
than quoting the file or its path.

Every command declares `bootstrap: false`, so neither the package
bootstrap chain nor the application's `bootstrap.php` runs, and the MCP
binary boots no application at all. Nothing here runs discovery: the
scaffold's generated test is what does that, on the project's own next
test run.

A command invocation as a whole is still not side-effect-free, and what
remains belongs to the framework launcher rather than to Orbitron (the
MCP binary loads no `.env` and compiles no cache):
`vendor/bin/kinetis` loads `.env` before it dispatches any command, and
under `APP_ENV=production` it compiles `.kinetis-cache/compiled.php`
when no valid artifact is present — see {doc}`caching`. `bootstrap:
false` prevents neither.

## See also

- {doc}`agent-workflow` — where a task goes after the context is read.
- {doc}`mcp-docs` — the same documentation as MCP resources, for a
  client that speaks the protocol.
- {doc}`cli` — how commands are discovered and what the binary does
  around them.
