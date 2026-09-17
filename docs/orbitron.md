# Orbitron

`kinetis/orbitron` is a development-only construction harness. It does
not contain a coding agent — you bring one — and it does not talk to a
model. What it gives an agent is two commands on `vendor/bin/kinetis`:
a portable Kinetis context document, and this project's installed
`kinetis/*` package inventory as JSON.

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
- the two-command workflow below;
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

## The workflow

1. `vendor/bin/kinetis orbitron:context` — once per task.
2. `vendor/bin/kinetis orbitron:inspect` — the installed versions.
3. Route the task through {doc}`agent-workflow`, then follow the
   matching recipe in {doc}`application-recipes`.
4. Before calling the change done, work through
   {doc}`agent-correctness` and run the project's own test suite.

## Output and exit codes

Both commands write exactly one document plus a trailing newline to
STDOUT, with fixed key and list order, and no progress text.

| Exit | Meaning |
|---|---|
| `0` | The document was written. |
| `2` | An unsupported `--format`, a bare `--format` naming nothing, or a positional argument. STDERR names the accepted invocation; STDOUT stays empty. |
| `1` | A launcher or uncaught failure, from `vendor/bin/kinetis` itself — see {doc}`cli`. |
| `70` | The command finished and disposing the request scope afterwards failed, which is the framework binary's own behavior, not Orbitron's. |

A command's `CommandArguments` cannot enumerate options it never reads,
so an option neither command consumes is ignored rather than rejected.
Orbitron adds no second argument parser for it, and the framework is not
changed to reject one.

(trust-boundary)=

## Trust boundary

Orbitron reads only Composer's installed-package metadata — no
application source, configuration or credentials. It writes no files,
opens no socket and starts no process.

That metadata is read through `Composer\InstalledVersions`, which loads
`vendor/composer/installed.php` itself, so the invocation does touch a
file inside `vendor/`. Orbitron reaches nothing beyond it, and never
reads a path the project supplies.

Both commands declare `bootstrap: false`, so neither the package
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
