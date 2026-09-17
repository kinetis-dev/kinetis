# Orbitron

`kinetis/orbitron` is a development-only construction harness. It does
not contain a coding agent — you bring one — and it does not talk to a
model. What it gives an agent is three commands on
`vendor/bin/kinetis`: a portable Kinetis context document, this
project's installed `kinetis/*` package inventory as JSON, and a
deterministic verification of this project's Composer layout.

Any agent that can run a shell command can use it. There is no MCP
server to configure, which is the difference from {doc}`mcp-docs`:
that package serves these pages to an MCP client, while Orbitron answers
a shell.

## Install

```console
composer require --dev kinetis/orbitron
```

`--dev` is the supported installation. Orbitron registers one scan root
and nothing else — no bootstrap, no route, no listener, no runtime
service, no configuration key. Application production code never depends
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

## The workflow

1. `vendor/bin/kinetis orbitron:context` — once per task.
2. `vendor/bin/kinetis orbitron:inspect` — the installed versions.
3. `vendor/bin/kinetis orbitron:verify` — the project layout.
4. Route the task through {doc}`agent-workflow`, then follow the
   matching recipe in {doc}`application-recipes`.
5. Before calling the change done, work through
   {doc}`agent-correctness` and run the project's own test suite.

## Output and exit codes

Every command writes exactly one document plus a trailing newline to
STDOUT, with fixed key and list order, and no progress text.

| Exit | Meaning |
|---|---|
| `0` | The document was written, and for `orbitron:verify` it reports no error. |
| `2` | An unsupported `--format`, a bare `--format` naming nothing, or a positional argument. STDERR names the accepted invocation; STDOUT stays empty. |
| `3` | `orbitron:verify` only: the verification completed, and the document it wrote reports at least one error. |
| `1` | A launcher or uncaught failure, from `vendor/bin/kinetis` itself — see {doc}`cli`. |
| `70` | The command finished and disposing the request scope afterwards failed, which is the framework binary's own behavior, not Orbitron's. |

A command's `CommandArguments` cannot enumerate options it never reads,
so an option no command consumes is ignored rather than rejected.
Orbitron adds no second argument parser for it, and the framework is not
changed to reject one.

(trust-boundary)=

## Trust boundary

Orbitron reads two things: Composer's installed-package metadata, and —
for `orbitron:verify` — the project's own `composer.json`. No other
application source, no configuration, no credentials. It writes no
files, opens no socket and starts no process.

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
bootstrap chain nor the application's `bootstrap.php` runs.

The invocation as a whole is still not side-effect-free, and what
remains belongs to the framework launcher rather than to Orbitron:
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
