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

You bring the coding agent. Orbitron gives it three commands on
`vendor/bin/kinetis`: a portable Kinetis context document, the project's
installed `kinetis/*` package inventory as JSON, and a deterministic
verification of the project's Composer layout. All three write one
document to STDOUT and change nothing.

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

## Output and exit codes

Every command writes exactly one document plus a trailing newline to
STDOUT, with fixed key and list order. None writes progress text.

| Exit | Meaning |
|---|---|
| `0` | The document was written, and for `orbitron:verify` it reports no error. |
| `2` | An unsupported `--format`, a bare `--format` naming nothing, or a positional argument. STDERR names the accepted invocation; STDOUT stays empty. |
| `3` | `orbitron:verify` only: the verification completed and the document it wrote reports at least one error. |
| `1` | A launcher or uncaught failure, from `vendor/bin/kinetis` itself. |
| `70` | The command finished, and disposing the request scope afterwards failed — also the framework binary's own behavior. |

`CommandArguments` cannot enumerate options a command never reads, so an
unknown option is ignored rather than rejected. Orbitron adds no parser
of its own for it.

## Trust boundary

Orbitron reads two things and nothing more: Composer's installed-package
metadata, and — for `orbitron:verify` — the project's own
`composer.json`, bounded as described above. No other application
source, no configuration, no credentials. It writes no files, opens no
socket and starts no process. Reading the installed metadata goes
through `Composer\InstalledVersions`, which loads
`vendor/composer/installed.php` itself; Orbitron reaches nothing beyond
it. Every command declares `bootstrap: false`, so no package or
application bootstrap runs.

The invocation as a whole is still not side-effect-free, and that
belongs to the framework launcher rather than to Orbitron:
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
