<p align="center">
  <img src="logo.svg" alt="Kinetis" width="420">
</p>

<p align="center">
  <strong>kinetis/orbitron</strong>
  <br>
  <strong>A development-only application construction harness for Kinetis</strong>
</p>

<p align="center">
  <a href="https://packagist.org/packages/kinetis/orbitron"><img src="https://img.shields.io/packagist/v/kinetis/orbitron?label=version" alt="Packagist Version"></a>
  <a href="https://packagist.org/packages/kinetis/orbitron"><img src="https://img.shields.io/packagist/dt/kinetis/orbitron" alt="Packagist Downloads"></a>
  <a href="https://packagist.org/packages/kinetis/orbitron"><img src="https://img.shields.io/packagist/php-v/kinetis/orbitron" alt="PHP Version"></a>
  <a href="https://packagist.org/packages/kinetis/orbitron"><img src="https://img.shields.io/packagist/l/kinetis/orbitron" alt="License"></a>
  <a href="https://github.com/kinetis-dev/kinetis/actions/workflows/ci.yml"><img src="https://github.com/kinetis-dev/kinetis/actions/workflows/ci.yml/badge.svg" alt="CI"></a>
</p>

---

Part of [Kinetis](https://kinetis.dev/), a non-blocking PHP framework for
API-first applications, developed in the
[kinetis-dev/kinetis](https://github.com/kinetis-dev/kinetis) monorepo.

You bring the coding agent. Orbitron gives it four commands on
`vendor/bin/kinetis`: a portable Kinetis context document, the project's
installed `kinetis/*` package inventory as JSON, a deterministic
verification of the project's Composer layout, and one health-endpoint
scaffold you preview before you apply it. Each writes one document to
STDOUT. Only `orbitron:scaffold --apply` changes anything, and what it
changes is two fixed files.

```console
composer require --dev kinetis/orbitron
```

Orbitron has no model of its own, no MCP server, no HTTP client and no
shell, and it needs no MCP configuration: any agent that can run a shell
command can use it.

## `orbitron:context`

```console
vendor/bin/kinetis orbitron:context
vendor/bin/kinetis orbitron:context --format=json
```

One document: what Orbitron is and what it does not establish, links to
the authoritative Kinetis guides, the command workflow, what each
command may change, the launcher behavior below, and the installed
`kinetis/*` package facts. `--format` accepts `markdown` (the default)
and `json`; both render the same document from the same facts.

## `orbitron:inspect`

```console
vendor/bin/kinetis orbitron:inspect
```

JSON only — this document exists to be parsed. Omitting `--format` and
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
ordered by name, one entry per name. A name that Composer only lists
because an installed package *replaces* or *provides* it has no version
and no install path, and is not reported. Install paths are read to make
that distinction and never appear in the output.

`orbitronVersion` is the detected `kinetis/orbitron` package version, so
it cannot disagree with what is installed; in the Kinetis monorepo that
is `dev-main`.

## `orbitron:verify`

```console
vendor/bin/kinetis orbitron:verify
```

JSON only, like `orbitron:inspect`. One deterministic document, with a
fixed key and check order:

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

`schemaVersion` is `1` and moves only when the document's shape changes.
`status` is `error` when any check failed and `pass` otherwise. `code` is
the stable machine value to branch on; it names an outcome and never
echoes the manifest, an exception message, or a path. `namespaces` is
present only when the whole layout is the admitted one — a
half-recognized project reports `null`.

### The layout it admits

- exactly one `autoload.psr-4` prefix whose mapping is the single string
  `"src/"`;
- exactly one `autoload-dev.psr-4` prefix whose mapping is the single
  string `"tests/"`;
- each prefix a non-empty PSR-4 namespace ending in `\`.

Any other mapping in either map is left alone, array-valued ones
included: a project declares whatever else it needs. Two prefixes
pointing at the same fixed path, or an array-valued mapping that reaches
it, are rejected rather than guessed at.

| Check | State | Code |
|---|---|---|
| `composerManifest` | `pass` | `manifest_read` |
| `composerManifest` | `error` | `manifest_missing`, `manifest_unreadable`, `manifest_oversize`, `manifest_not_json`, `manifest_not_an_object` |
| `productionNamespace`, `testNamespace` | `pass` | `namespace_unique` |
| `productionNamespace`, `testNamespace` | `error` | `psr4_map_missing`, `path_unmapped`, `path_ambiguous`, `path_mapping_not_a_string`, `namespace_invalid` |
| `productionNamespace`, `testNamespace` | `skip` | `manifest_unusable` |

A manifest that could not be used makes both namespace checks `skip`,
not `error`: the layout was never seen, so nothing is claimed about it.

### What it proves, and what it does not

It proves that this project's Composer layout is the narrow one Orbitron
supports. That layout is a deliberate prerequisite for the scaffolding
built on it — a fixed pair of namespaces is what lets a generated class
be placed without guessing — and it is narrower than anything Kinetis
itself demands.

Discovery asks for much less: `Kinetis\Cache\NamespaceScanner` walks
every string prefix under `autoload.psr-4`, at any directory, accepting
array-valued mappings, and it never reads `autoload-dev` at all. An
`error` here therefore does not mean discovery is broken — a project
with three production prefixes, or with its tests under a path this
rejects, still has every route, command, tool and listener discovered as
usual.

One real discovery failure is inside what the production check catches,
though: a project with no usable `autoload.psr-4` map has no root to
scan, so discovery finds nothing and says so through a single
`error_log()` line. That project reports `psr4_map_missing` or
`path_unmapped` here instead.

It proves nothing else. It is not evidence about request isolation,
non-blocking I/O, security, route uniqueness, or the correctness of any
application code. The project's own tests and review settle those.

The only project file it reads is `<project root>/composer.json`, found
from the root the framework's own `Kinetis\Runtime\ProjectRoot::detect()`
reports — never a path passed on the command line. It is read through a
bounded stream read of at most 1 MiB, plus the single byte that tells an
admitted manifest from an oversized one; a larger file is refused
without ever being read whole.

## `orbitron:scaffold`

```console
vendor/bin/kinetis orbitron:scaffold
vendor/bin/kinetis orbitron:scaffold --apply
```

JSON only, like the two commands above. Without `--apply` it is a
preview: every precondition is read and the plan is written, with
nothing touched on disk.

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

`mode` is `preview` or `apply`. `status` is `ready` (the preview holds),
`created` (both files exist), `refused` (a precondition the project does
not meet, decided before anything was opened) or `failed` (a write that
started and did not finish). `codes` are the stable machine values to
branch on, in a fixed order. `targets` is the complete write set, always
both paths and always in this order. `remainingFiles` is empty unless a
rollback could not put the project back.

### What it builds

Exactly two files, and nothing about them is configurable — there is no
name, path, template, source-body or plugin input:

- `src/Http/HealthController.php`, under the production namespace the
  layout check found, declaring `#[Get('/health')]` and returning
  `['status' => 'ok']`. The framework encodes that array as
  `{"status":"ok"}` with the route's status, the same as any other array
  a controller returns.
- `tests/Http/HealthControllerTest.php`, under the test namespace,
  extending `Kinetis\Testing\ApplicationTestCase` and issuing two
  sequential `GET /health` requests against one booted application,
  asserting the same success response both times.

Neither generated file mentions Orbitron. They import the framework and
the framework's testing API only, so removing Orbitron leaves them
working.

### What it requires

- the admitted layout above, with both namespaces valid;
- `src`, `src/Http`, `tests` and `tests/Http` already present as real
  directories, none of them a symlink, each resolving inside the project
  root;
- neither target occupied — a regular file, a directory, a symlink, or a
  symlink pointing at nothing all count as occupied.

Orbitron creates no directory. A project missing `src/Http` is refused,
not filled in.

| Status | Code | Meaning |
|---|---|---|
| `ready` | `scaffold_ready` | The preview holds: both files can be created. |
| `created` | `scaffold_created` | Both files exist, every byte written, flushed and closed. |
| `refused` | `manifest_missing`, `manifest_unreadable`, `manifest_oversize`, `manifest_not_json`, `manifest_not_an_object`, `manifest_unusable`, `psr4_map_missing`, `path_unmapped`, `path_ambiguous`, `path_mapping_not_a_string`, `namespace_invalid` | The layout is not the admitted one; these are `orbitron:verify`'s own codes, from the same reader. |
| `refused` | `directory_missing` | One of the four fixed directories is absent or is not a directory. |
| `refused` | `directory_outside_project` | One of them resolves outside the project root. |
| `refused` | `directory_symlinked` | One of them is a symlink. |
| `refused` | `target_exists` | One of the two targets is occupied. |
| `failed` | `write_failed` + `rolled_back` | A file could not be created or written; everything this invocation created was removed. |
| `failed` | `write_failed` + `rollback_failed` | The removal failed too. `remainingFiles` names exactly the paths that may still be there. |

### Preview, then apply

`--apply` does not consume the preview. It re-reads the manifest, the
four directories, their symlink state and both targets immediately
before it writes, so a file that appeared in between is a refusal rather
than an overwrite. `--apply` takes no value: `--apply=yes` is a rejected
invocation.

Each file is created with `fopen($path, 'x+b')`, which fails rather than
truncating anything already there, and every byte is written in a loop
that treats a failed write, or one that accepts nothing, as the end of
the attempt. If the second file cannot be created or finished, every
file this invocation created is removed — never one that was already
there. A removal that fails is reported, with the paths that may remain.

### What it cannot tell you

Whether the project already routes `GET /health` somewhere else.
Orbitron reads no application source and runs no discovery, so a
conflicting route is invisible to it and the apply succeeds. The
generated test is what surfaces it: the framework's own route discovery
refuses two controllers claiming one path, and the first run of the test
suite after the scaffold says so.

## Output and exit codes

Every command writes exactly one document plus a trailing newline to
STDOUT, with fixed key and list order. None writes progress text.

| Exit | Meaning |
|---|---|
| `0` | The document was written; the verification reports no error, and the scaffold preview is ready or its apply completed. |
| `2` | An unsupported `--format`, a bare `--format` naming nothing, an `--apply` carrying a value, or a positional argument. STDERR names the accepted invocation; STDOUT stays empty. |
| `3` | `orbitron:verify` and `orbitron:scaffold` only: the operation completed and the document it wrote reports an error, a refusal, or a failed write. |
| `1` | A launcher or uncaught failure, from `vendor/bin/kinetis` itself. |
| `70` | The command finished, and disposing the request scope afterwards failed — also the framework binary's own behavior. |

`CommandArguments` cannot enumerate options a command never reads, so an
unknown option is ignored rather than rejected. Orbitron adds no parser
of its own for it.

## Trust boundary

Orbitron reads three things and nothing more: Composer's
installed-package metadata, the project's own `composer.json` — bounded
as described above — and, for `orbitron:scaffold`, the existence and
symlink state of four fixed directories and two fixed paths. No other
application source, no configuration, no credentials. It opens no socket
and starts no process. Reading the installed metadata goes through
`Composer\InstalledVersions`, which loads `vendor/composer/installed.php`
itself; Orbitron reaches nothing beyond it. Every command declares
`bootstrap: false`, so no package or application bootstrap runs.

It writes two files, both fixed, both only on `orbitron:scaffold
--apply`, and both through a create that refuses an occupied path. No
command line supplies a path to any of this: the project root comes from
the framework's own `Kinetis\Runtime\ProjectRoot::detect()`, and every
name below it is a constant.

The invocation as a whole is still not side-effect-free, and what
remains belongs to the framework launcher rather than to Orbitron:
`vendor/bin/kinetis` loads `.env` before it dispatches any command, and
under `APP_ENV=production` it compiles `.kinetis-cache/compiled.php`
when no valid artifact is present. `bootstrap: false` prevents neither.

## Removal

Orbitron is a `require-dev` package. Generated and application
production code never depends on it, so
`composer remove --dev kinetis/orbitron` changes nothing an application
does — only the commands stop being discovered.

See the [Orbitron documentation](https://kinetis.dev/docs/orbitron.html)
for the full workflow, and
[Agent Workflow](https://kinetis.dev/docs/agent-workflow.html) for where
a task goes next.

## License

MIT — see [LICENSE](LICENSE).
