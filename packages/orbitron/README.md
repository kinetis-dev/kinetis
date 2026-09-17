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

You bring the coding agent. Orbitron gives it two commands on
`vendor/bin/kinetis`: a portable Kinetis context document, and the
project's installed `kinetis/*` package inventory as JSON. Both read
Composer's installed-package records and write to STDOUT. Nothing else.

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
the authoritative Kinetis guides, the two-command workflow, what each
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

## Output and exit codes

Both commands write exactly one document plus a trailing newline to
STDOUT, with fixed key and list order. Neither writes progress text.

| Exit | Meaning |
|---|---|
| `0` | The document was written. |
| `2` | An unsupported `--format`, a bare `--format` naming nothing, or a positional argument. STDERR names the accepted invocation; STDOUT stays empty. |
| `1` | A launcher or uncaught failure, from `vendor/bin/kinetis` itself. |
| `70` | The command finished, and disposing the request scope afterwards failed — also the framework binary's own behavior. |

`CommandArguments` cannot enumerate options a command never reads, so an
unknown option is ignored rather than rejected. Orbitron adds no parser
of its own for it.

## Trust boundary

Orbitron reads only Composer's installed-package metadata — no
application source, configuration or credentials. It writes no files,
opens no socket and starts no process. Reading that metadata goes
through `Composer\InstalledVersions`, which loads
`vendor/composer/installed.php` itself; Orbitron reaches nothing beyond
it. Both commands declare `bootstrap: false`, so no package or
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
does — only the two commands stop being discovered.

See the [Orbitron documentation](https://kinetis.dev/docs/orbitron.html)
for the full workflow, and
[Agent Workflow](https://kinetis.dev/docs/agent-workflow.html) for where
a task goes next.

## License

MIT — see [LICENSE](LICENSE).
