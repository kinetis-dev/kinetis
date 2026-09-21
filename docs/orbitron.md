# Orbitron

Kinetis does not hand you a generic dashboard or force your application
into a prebuilt scaffold. It gives you something more adaptable:
Orbitron, the development harness that equips your chosen AI coding
agent with Kinetis context, project inspection, verification and
controlled scaffolding. Describe the application you need, and build
against the packages and versions actually installed.

`kinetis/orbitron` is a development-only construction harness, and a
harness rather than an agent: it contains no model, talks to none, and
generates no application of its own. You bring the agent. What Orbitron
gives it is four documents: a portable Kinetis context document, this
project's installed `kinetis/*` package inventory as JSON, a
deterministic verification of this project's Composer layout, and one
health-endpoint scaffold that is previewed before it is applied. None of
them is evidence that application code is correct — {doc}`agent-correctness`
and the project's own test suite are.

Reach them either way. Any agent that can run a shell command uses the
four commands on `vendor/bin/kinetis`; an agent that speaks MCP registers
`vendor/bin/kinetis-orbitron-mcp` and calls the same documents as tools
(see [Over MCP](#over-mcp)). Both adapt the same services, so neither can
report something the other does not. Over MCP only, three further tools
reach an installed package's own source — a `kinetis/*` one first, and
any other dependency this project installed the same way: one reads a
bounded window of a file, one searches one file for a literal string,
and one lists the direct children of one directory — see
[Read installed package source](#read-installed-package-source),
[Search installed package source](#search-installed-package-source) and
[List installed package source](#list-installed-package-source) —
because a shell-only agent already has the project's `vendor/` tree to
read directly.

Over MCP that one server is also the whole project-local registration.
Alongside Orbitron's own context and tools it publishes the
`kinetis_read_doc` tool that reads one bounded window of a page, and
these documentation pages whole as `kinetis://docs/*` resources, both
served by {doc}`mcp-docs` from inside the same process — so an agent
reads the current guidance on the connection it is already on, and there
is no second server to configure or to skip.

`kinetis/skeleton` arrives with the whole wiring in place;
[Equip an existing project](#equip-an-existing-project) is the same
wiring as a recipe for a project that already exists.

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
    "orbitronVersion": "1.1.0",
    "packages": [
        {
            "name": "kinetis/framework",
            "version": "1.12.0"
        },
        {
            "name": "kinetis/mcp-docs",
            "version": "1.4.0"
        },
        {
            "name": "kinetis/mcp-protocol",
            "version": "1.0.0"
        },
        {
            "name": "kinetis/orbitron",
            "version": "1.1.0"
        }
    ]
}
```

`packages` carries every installed package under the `kinetis/` vendor,
ordered by name, one entry per name. A name Composer lists only because
an installed package *replaces* or *provides* it carries no version and
no install path, and is not reported. Install paths are read to make
that distinction and never reach the output.

The Composer root project is left out too. Composer lists it among the
installed packages, but it is the project being developed rather than
something the project installed, so a root under the `kinetis/` vendor —
`kinetis/skeleton`, or `kinetis/orbitron` itself while that package is
developed — is not a dependency to report.

`orbitronVersion` is the detected `kinetis/orbitron` package version
rather than a constant maintained beside it, so the two cannot
disagree. It is read internally, from the same retained set, so it still
names the version when Orbitron is the root project it leaves out of
`packages`. In the Kinetis monorepo it reads `dev-main`.

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
    "orbitronVersion": "1.1.0",
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
    "orbitronVersion": "1.1.0",
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

(read-installed-package-source)=

## Read installed package source

`orbitron_read_package_source` is MCP-only: reading
`vendor/<vendor>/<package>` directly is the shell equivalent, so the CLI
does not duplicate it. It returns one bounded line window of one real
installed, non-root package's own file, re-read live on every call — the
published guides come from main, but this reads the exact source this
project has installed, at the version it is installed at.

Any real installed, non-root package is admitted, whatever its vendor.
`orbitron_inspect` names the `kinetis/*` packages; the project's own
`composer.lock` names every other, which is where a third-party name
comes from. Kinetis stays the first place to look — this is bounded
evidence of an exact dependency, not a file browser — and the Composer
root project, meaning the application itself, is never readable.

Arguments:

| Argument | Type | Constraint |
|---|---|---|
| `package` | string | Required, non-empty, a real installed, non-root package name. |
| `path` | string | Required, non-empty, at most 256 Unicode code points, relative with `/` separators. |
| `startLine` | integer | Optional, at least 1, default 1. |
| `lineCount` | integer | Optional, 1 to 200, default 200. |

`path` must resolve to exact `composer.json`, exact `README.md`, or a file
beneath `src/`, `bin/` or `resources/`. The resolved target — a symlink
included — must stay inside that same admitted location, must be a
regular file, must be at most 1 MiB, and must be UTF-8 text with no NUL
byte. A call outside the schema — an unknown argument, a wrong type, or a
value out of range — is a JSON-RPC `-32602` protocol error before any
lookup runs, not a refusal document.

A success reports `status: "ok"`, `package`, `version`, `path`,
`startLine`, `endLine`, `hasMore` and `content`. A refusal reports only
`status: "error"` and one `code`:

| `code` | Meaning |
|---|---|
| `package_unknown` | `package` is not a real installed, non-root package. |
| `path_not_admitted` | `path` does not name an admitted location, or a symlink resolves to a different, unserved location still inside the package. |
| `source_missing` | The resolved target does not exist. |
| `source_unreadable` | The resolved target lies outside the package, is not a regular file, or could not be opened or read. |
| `source_oversize` | The file is larger than 1 MiB. |
| `source_not_text` | The file contains a NUL byte or is not valid UTF-8. |
| `line_out_of_range` | `startLine` is past the file's last line. |

The inventory this tool resolves a package against is the project's own
generated one, read again for every call, so a completed dependency
change is visible to the next one and an edit to an already-installed
source file is visible on the very next call. This tool never reads the
application's own controllers, tests, configuration or credentials, and
never reads the Composer root project or anything in an installed
package outside the five admitted locations, that package's own tests
included; that boundary is [Trust boundary](#trust-boundary).

(search-installed-package-source)=

## Search installed package source

`orbitron_search_package_source` is MCP-only for the same reason, and it
answers the question a window cannot: *where* in the file. It reports
every line of one admitted file that contains a literal string, so a
known file is searched rather than paged through window by window.

Arguments:

| Argument | Type | Constraint |
|---|---|---|
| `package` | string | Required, non-empty, a real installed, non-root package name. |
| `path` | string | Required, non-empty, at most 256 Unicode code points, relative with `/` separators. |
| `query` | string | Required, non-empty, at most 256 Unicode code points. |
| `startLine` | integer | Optional, at least 1, default 1. |

`package` and `path` are admitted exactly as [Read installed package
source](#read-installed-package-source) admits them, by the same code,
and every `code` in that refusal table is a refusal here too — a
`startLine` past the last line, or an empty file, is `line_out_of_range`.
A call outside the schema is the same JSON-RPC `-32602` before any
lookup runs.

The scan is literal and case-sensitive. There is no regular expression,
no fuzzy or semantic matching, no ranking, no context-line option, no
case mode and no result-count argument, and it searches the one file the
call names — never a directory, never a whole package.

A success reports `status: "ok"`, `package`, `version`, `path`, `query`,
`startLine`, `matches` and `hasMore`. `matches` is a source-ordered list
of `{"line": <integer>, "content": <string>}`, each line without the
`\n` or `\r\n` the file stores after it, and at most 50 of them.
Finding nothing is a success with `matches: []` and `hasMore: false`,
not a refusal.

`hasMore: true` means a later line matches as well. No cursor comes
back: continue with `startLine` set to the last reported line plus one.

(list-installed-package-source)=

## List installed package source

`orbitron_list_package_source` is MCP-only for the same reason, and it
answers the question the other two cannot: *which file*. It reports the
direct children of one directory of one real installed, non-root
package, so a file is found when the package is known and the path is
not. A package README is not a class index, and a guessed path is
`source_missing`.

Arguments:

| Argument | Type | Constraint |
|---|---|---|
| `package` | string | Required, non-empty, a real installed, non-root package name. |
| `path` | string | Required, non-empty, at most 256 Unicode code points, relative with `/` separators. |

`path` must name `src`, `bin` or `resources` itself, or a directory
beneath one of them; the package root and its two readable root files
are not listable. There is no recursion, pattern, filter or paging
argument: a subdirectory is listed by naming it in the next call, and a
call outside the schema is the same JSON-RPC `-32602` before any lookup
runs.

A success reports `status: "ok"`, `package`, `version`, `path` and
`entries`. `entries` is the direct children, each as exactly a `name`
and a `type` of `"file"` or `"directory"`, in bytewise name order —
uppercase before lowercase, and never a locale's order. An empty
directory is a success with `entries: []`, not a refusal.

A child is reported only when its resolved target is a regular file or a
directory that still lies in the same admitted location, which is the
rule a read of that name would apply: a link out of the package, a link
into a part of it this tool does not serve, a dangling link, and a
socket, device or fifo are absent rather than offered as something to
read next. No resolved path is reported either way.

| `code` | Meaning |
|---|---|
| `package_unknown` | `package` is not a real installed, non-root package. |
| `path_not_admitted` | `path` does not name a listable directory — the package root and a root file included — or a symlink resolves to a different, unserved location still inside the package. |
| `source_missing` | The resolved target does not exist. |
| `source_unreadable` | The resolved target lies outside the package, the directory could not be opened, or a child's name is not valid UTF-8. |
| `source_not_directory` | The admitted path resolves to a regular file. |
| `directory_oversize` | More than 200 children would be reported. |

`directory_oversize` refuses the whole listing rather than returning the
first 200 names: a prefix of a directory answers *what is in here* with
something else, and no cursor exists to finish it with. Read the
directory under `vendor/<vendor>/<package>` when one is that large.

### Finding the file

1. For a known class or symbol, derive its file from the class name and
   that package's own `composer.json` autoload map — itself an admitted
   path — then search the file and read a window around a reported line.
2. For a known package but an unknown file, list `src` and then the
   directory the task is about, or search that package's `README.md` for
   the option, setting or term; either names the file to go to next.
3. Only when none of those yields a file, or a call is refused, read
   `vendor/<vendor>/<package>` directly and record why. No tool here
   searches across a package, so choosing the file is still the
   caller's own work.

## The workflow

1. `vendor/bin/kinetis orbitron:context` — once per task.
2. `vendor/bin/kinetis orbitron:inspect` — the installed versions.
3. `vendor/bin/kinetis orbitron:verify` — the project layout.
4. `vendor/bin/kinetis orbitron:scaffold` — the health-endpoint plan,
   then `--apply` when the plan is what you want.
5. Route the task through {doc}`agent-workflow` — over MCP, read it
   with `kinetis_read_doc` from line 1, taking the next window only
   while the section the recipe named, or a named unknown, is still
   unresolved, and the `kinetis://docs/agent-workflow` resource when
   the whole page is what you need — then follow the matching recipe in
   {doc}`application-recipes`. Over MCP, read installed
   source with `orbitron_read_package_source` when a guide and the
   installed version could differ, or when an exact dependency's
   behavior is what the change turns on, locating the file first with
   `orbitron_list_package_source` and the line with
   `orbitron_search_package_source`; a shell-only agent reads
   `vendor/<vendor>/<package>` directly instead.
6. Before calling the change done, work through
   {doc}`agent-correctness` and run the project's own test suite.
7. Run one suite at a time against a shared database, broker or object
   store — {ref}`testing-serialized-shared-state`.

An MCP client runs the same steps as tool and resource reads; see
[Over MCP](#over-mcp).

(over-mcp)=

## Over MCP

```console
vendor/bin/kinetis-orbitron-mcp
```

A stdio MCP server speaking `2025-06-18`, for any client that launches a
server as a subprocess. Register the launcher as a stdio server named
`orbitron`, the way that client registers any other: the server takes no
argument, needs no environment and reads nothing from the registration
but the command to run.

[Equip an existing project](#equip-an-existing-project) is the same
registration checked in, so every developer on the project gets it
without running anything — and that is where the per-client
configuration paths and discovery differences are.

| Tool or resource | What it returns |
|---|---|
| `orbitron_inspect` | The `orbitron:inspect` document. Read-only. |
| `orbitron_verify` | The `orbitron:verify` document. Read-only; an error document comes back as an MCP error result still carrying the document. |
| `orbitron_scaffold_plan` | The `orbitron:scaffold` preview document. Read-only. |
| `orbitron_scaffold_apply` | The `orbitron:scaffold --apply` document, and creates the two files. |
| `orbitron_read_package_source` | One line window of one installed package's own source — see [Read installed package source](#read-installed-package-source). Read-only. |
| `orbitron_search_package_source` | The lines of one such file that contain a literal string — see [Search installed package source](#search-installed-package-source). Read-only. |
| `orbitron_list_package_source` | The direct children of one directory of such a package — see [List installed package source](#list-installed-package-source). Read-only. |
| `kinetis_read_doc` | One line window of one page of this documentation — see [The documentation resources](#the-documentation-resources). Read-only, and the one tool that reaches the network. |
| `kinetis://orbitron/context` | The `orbitron:context` document, as Markdown. |
| `kinetis://docs/<page>` | One page of this documentation, as Markdown. `resources/list` names every page; `kinetis://docs/agent-workflow` is where a task starts. |

```{warning}
`orbitron_scaffold_apply` writes to the project. Selecting it *is* the
mutation request: it takes no argument, and your MCP client's own
approval policy is what decides whether it runs. It is annotated
`destructiveHint: true` and `idempotentHint: false` — a second apply
refuses, because the targets exist by then.
```

Four tools publish a closed, empty input schema and refuse a call that
carries any argument at all. Three of the rest,
`orbitron_read_package_source`, `orbitron_search_package_source` and
`orbitron_list_package_source`, take `package`, `path` and — for the
first two — either a line window or a literal query: each schema is
validated in full before the package lookup, and `path` is then admitted
against a fixed set of locations, with the resolved target re-admitted,
before anything reaches the filesystem — see [Read installed package
source](#read-installed-package-source). The last,
`kinetis_read_doc`, takes a page URI from the fixed catalogue and an
optional line window, and {doc}`mcp-docs` publishes, validates and
answers it — Orbitron surfaces that tool unchanged rather than restating
any of it. No message can otherwise name a
project root, a source body, a URL, a template or a command: the project
root comes from Composer's own bin proxy, exactly as it does for
`vendor/bin/kinetis`, and every other name is a constant. The server
never boots the Kinetis application, so running it registers no route,
listener or bootstrap.

The server evaluates this project's generated
`vendor/composer/installed.php` again for every operation that reports or
uses installed package facts, so a completed `composer require`, `remove`
or `update` is visible to the next such call and no restart or reconnect
is involved. One operation reads it once, so the versions a document
reports and the source a read opens cannot come from two different
inventories, and the snapshot is discarded with the response — nothing
is watched, polled or cached between calls. An inventory that is absent
or not the generated shape fails an operation that needs it rather than
producing an answer from an older one. Every other document, and the file
content and directory entries the three installed-source tools report,
are re-read on each call too, and nothing about one call survives into
the next.

### The documentation resources

{doc}`mcp-docs` owns the page catalogue, the fetch and the window tool.
Orbitron requires it, holds a `DocsApplication` and hands every
`kinetis://docs/*` read and every `kinetis_read_doc` call straight to
it, composing that server rather than copying it — so a page added to
`kinetis/mcp-docs` appears here without a change in Orbitron, and
registering Orbitron is the whole registration. The package stays
framework-agnostic and separately installable: a project that wants the
documentation without the harness registers `vendor/bin/kinetis-mcp-docs`
on its own instead.

A page is read a window at a time with `kinetis_read_doc`: that tool
takes the page URI plus an optional `startLine` and `lineCount`, returns
at most 200 lines and 32 KiB of content per call, and reports the
`endLine` it reached and whether the page continues — so a page is read
in order, without a cursor, and its windows concatenate back into the
page exactly, as long as the page has not changed on `main` between
calls: every call fetches it again, with no snapshot held across them.
Take the next window only while the section you were routed to, or a
named unknown, is still unresolved; read the whole page as its
`kinetis://docs/<page>` resource when the complete page is what you
need. {doc}`appendix-mcp-docs` holds that tool's full argument, bound
and refusal contract.

Reading a page, either way, is the one Orbitron operation that leaves
the machine. The page is fetched when it is read, from the fixed
main-branch origin built out of that package's own two constants —
nothing in a message chooses an origin, a ref or a path:

- TLS verifies the peer and the host name;
- no redirect is followed, and any status other than `200` fails;
- a 10-second idle timeout and a 30-second total deadline bound the
  request;
- the body is abandoned once it passes 4 MiB, rather than accumulated;
- a body that is not valid UTF-8 is refused rather than encoded.

A fetch that fails is a generic MCP error naming only the URI that was
asked for. The URL, the status and the transport's own message go to the
server's stderr, where the client's log shows them; stdout carries
JSON-RPC frames and nothing else. {doc}`appendix-mcp-docs` holds the
complete fetch and protocol contract.

Those pages are published from `main`, so they can describe behavior
newer than this project has installed. `orbitron_inspect` and the
matching source under `vendor/` stay the authority for anything
version-sensitive — see {doc}`agent-workflow`.

## Equip an existing project

`kinetis/skeleton` ships this wiring, so a new project has it from the
first `docker compose up`. The same steps turn an existing Kinetis
application into one an MCP-capable agent can be pointed at, without a
global setting on anyone's machine and without widening a single
approval.

### 1. The dependency

```console
composer require --dev kinetis/orbitron
```

That one package brings the documentation with it: Orbitron requires
`kinetis/mcp-docs`, which it composes to serve the `kinetis://docs/*`
resources and the window tool. Nothing else is installed or registered
for them.

In a monorepo that resolves siblings through `path` repositories, add
`kinetis/mcp-docs` and `kinetis/mcp-protocol` to `require-dev` as well,
with a `path` repository for each. Orbitron requires both, and a root
whose `minimum-stability` is `stable` will not accept a sibling's
`dev-main` from Packagist — naming them is the smallest fix, and it
beats loosening the whole project's stability.

### 2. The launcher

An MCP client launches a server as a subprocess on the host. When PHP
and the vendor directory are on the host, the launcher is the binary
itself, `./vendor/bin/kinetis-orbitron-mcp`, and this step is already
done.

When the project runs in Docker — the skeleton's case — the server lives
next to the code it reports on, in a disposable container derived from
the application service rather than inside it, and a one-file bridge
relays it:

```sh
#!/bin/sh
set -e

project_directory=$(CDPATH='' cd -- "$(dirname -- "$0")/.." && pwd)

exec docker compose --project-directory "$project_directory" \
    run --rm -T --no-deps --entrypoint php app \
    vendor/bin/kinetis-orbitron-mcp
```

Save it as `bin/orbitron-mcp`, `chmod +x` it, and commit it. Every part
of it is load-bearing:

- the project directory comes from the script's own location, so the
  checked-in configuration works in any clone, at any path, and carries
  no absolute user path;
- `run` starts a one-off container that shares the `app` service's image
  and mounts, but not its process lifecycle, so restarting, recreating
  or rebuilding `app` does not disconnect an established session. Docker
  itself stopping, and the client ending this script's process, always
  do. A complete `docker compose down` is outside that guarantee either
  way: on Compose v5.5.1, a live one-off still holds the project
  network, so `down` can remove `app`, leave the session alive,
  and still exit nonzero over that network being in use;
- `--rm` removes that one-off container when the process ends;
- `--no-deps` starts only this one container, not a generic project's
  other services;
- `--entrypoint php` bypasses the application entrypoint's unconditional
  `composer install`, which this container must not repeat or race
  against the `app` container's own;
- `-T` is required: an allocated TTY would rewrite the
  newline-delimited JSON-RPC frames the protocol depends on.

`docker compose up --build -d` must have completed at least once, so
the image is available and its vendor volume is populated with
dependencies. Before that, this command still runs and fails: Compose
can build the image and create the volume itself, but
`vendor/bin/kinetis-orbitron-mcp` does not exist inside it, because
nothing has run the entrypoint's `composer install`. The client reports
the server as unavailable, and the agent is expected to say so rather
than proceed.

Name the service to match your own Compose file if it is not `app`.

### 3. The instructions

One file, `AGENTS.md` at the project root, is the whole agent contract.
It is what makes the harness load-bearing rather than optional: the
documents exist either way, and this is what says when they must be
read. Require, on the first application task of a session, the
preconditions under [Start it, in this
order](#start-it-in-this-order) and then this handshake:

1. confirm the `orbitron` server is connected and its tools and
   resources are listed;
2. read `kinetis://orbitron/context`;
3. read `kinetis://docs/agent-workflow`, and route the task through the
   pages it names rather than answering from memory;
4. call `orbitron_inspect`, and treat those versions as the installed
   ones, in preference to any page describing Kinetis `main`;
5. call `orbitron_verify`.

Then require one of two outcomes, and nothing in between. On success,
with `orbitron_verify` reporting `"status": "pass"`, a single readiness
line beginning `Orbitron ready` — naming the installed framework version
and the verified namespaces — and then the user's actual request. On any
step that is unavailable, disconnected or failing, no application change
at all: the exact failing step and its error or document `code`, the
matching diagnostic from the project's README, and then a wait for the
user.

Say which steps are not the agent's. Bringing the stack up is something
an agent with a shell can do; reconnecting the client and approving the
server are the user's, and an agent that cannot do either must say so
and wait rather than work around it.

Require an `orbitron_scaffold_plan` call before any
`orbitron_scaffold_apply`, and require explicit user intent for the
apply. It is the only tool that writes.

### 4. The imports

```{code-block} markdown
:caption: CLAUDE.md, and GEMINI.md

@AGENTS.md
```

Both clients resolve an `@path` import, so the contract stays in one
file and cannot drift between three. Do not restate it in either.

### 5. The project MCP configuration

Three checked-in files, one per client, each naming the same server and
the same launcher:

```{code-block} json
:caption: .mcp.json — Claude Code and compatible clients

{
    "mcpServers": {
        "orbitron": {
            "type": "stdio",
            "command": "./bin/orbitron-mcp",
            "args": []
        }
    }
}
```

```{code-block} toml
:caption: .codex/config.toml — Codex

[mcp_servers.orbitron]
command = "./bin/orbitron-mcp"
args = []
```

```{code-block} json
:caption: .gemini/settings.json — Gemini CLI

{
    "mcpServers": {
        "orbitron": {
            "command": "./bin/orbitron-mcp"
        }
    }
}
```

A project with no bridge names `./vendor/bin/kinetis-orbitron-mcp` in
all three instead.

Keep them this small. No `env` block, because the server needs no
credential and forwarding one would put it somewhere it was never meant
to go. No `trust`, no preapproved tool list, no auto-start of anything
else.

A client that reads none of these three file names is not excluded: it
registers `./bin/orbitron-mcp` as a stdio server named `orbitron` the
way it registers any other, and reads `AGENTS.md` when you point it
there.

### 6. Trust and approval

These files register a server. They deliberately change no policy: they
carry no credential, no trust override and no preapproval, so whatever
trust and approval policy the developer's client already runs under
stays authoritative. Keep it that way — a repository that preapproves
its own tools has moved a decision from the developer to whoever can
open a pull request.

That policy belongs to the client, and so does where the client looks
for the contract:

| Client | Reads instructions from | Project MCP configuration |
|---|---|---|
| Claude Code | `CLAUDE.md`, which imports `AGENTS.md` | `.mcp.json`, subject to its project-server prompt in an interactive session; its documented non-interactive and policy-managed modes can behave differently |
| Codex | `AGENTS.md` | `.codex/config.toml`, read only for a trusted project |
| Gemini CLI | `GEMINI.md`, which imports `AGENTS.md` | `.gemini/settings.json`, subject to workspace trust; an omitted server `trust` leaves that server's default `false` |
| Any other MCP-capable client | `AGENTS.md`, when you point it there | register `./bin/orbitron-mcp` as a stdio server named `orbitron` |

`AGENTS.md` cannot grant any of this, and cannot register anything
either: a Markdown file read inside a running session adds no server to
it. The contract says so rather than letting an agent claim otherwise.

(start-it-in-this-order)=

### 7. Start it, in this order

Starting the server has an order, because it runs in a disposable
container derived from the project's `app` service, the client launches
one server process per session, and the client's own approval sits
between the two. The first three are preconditions; only the fourth is
the handshake.

1. **Complete the stack's initial setup.** `bin/orbitron-mcp` launches a
   disposable container built from the `app` service's image, sharing
   its project and vendor mounts. `docker compose up --build -d` must
   have completed at least once, so the image is available and its
   vendor mount is populated with dependencies:

   ```console
   docker compose ps
   docker compose up --build -d
   ```

2. **Reload, restart or reconnect the client** — when the MCP
   configuration arrived or changed after the session started, or when
   an earlier launch was attempted before the stack's initial setup
   completed. Docker itself stopping, and the client ending this
   script's process, always end an Orbitron session; an `app` restart,
   recreation or rebuild does not, because the launcher no longer runs
   inside that container. A complete `docker compose down` is outside
   that guarantee either way: on Compose v5.5.1, a live session keeps
   the project network in use, so `down` can remove `app`, leave the
   session alive, and still exit nonzero over that network being in
   use — end the session first when you need a complete
   teardown.
3. **Approve the project-local `orbitron` server**, under the client's
   own policy above.
4. **Run the handshake** from [3. The
   instructions](#3-the-instructions): the `orbitron` tools and
   resources, `kinetis://orbitron/context`,
   `kinetis://docs/agent-workflow`, `orbitron_inspect`,
   `orbitron_verify`, then one readiness line.

A project running PHP on the host skips step 1: the launcher is
`./vendor/bin/kinetis-orbitron-mcp` and there is no container to bring
up.

### 8. Diagnostics

Write these into the project's README, because the contract sends the
agent there. These failures cover what actually happens:

| Symptom | What it means | What to do |
|---|---|---|
| The server fails to start | The stack has not completed its initial setup — the `app` image is not built, or its vendor volume has no dependencies installed | `docker compose up --build -d`, then restart the client |
| The server was there and is gone | Docker stopped, or the client itself ended | Restart or reconnect the client; an `app` restart, recreation or rebuild alone does not cause this |
| `docker compose down` exits nonzero over a network still in use | A live Orbitron session keeps its one-off container attached to the project network, so `down` removed `app` but cannot remove the network yet | End or close the client session, then run `down` again; relaunch the client once the stack is back up |
| The server shows as disconnected | The bridge or the client, not yet distinguished | Run the launcher by hand (below). A handshake reply puts it on the client side: its trust or approval policy, or a tool catalog that has not refreshed |
| `orbitron_verify` reports `error` | `composer.json` is outside [the layout Orbitron admits](#the-layout-it-admits) | Read the `code` on each failed check |
| A `kinetis://docs/*` read fails | The fetch to the documentation origin failed, timed out, or returned something unusable | Read the server's stderr in the client's log for the URL and reason; every other document is local and unaffected |

The launcher is an ordinary command, so a single line proves the whole
path from host to server:

```console
printf '%s\n' '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"manual","version":"0"}}}' | ./bin/orbitron-mcp
```

A JSON-RPC result naming `kinetis-orbitron-mcp` means the bridge, the
container and the server are all fine.

### Without MCP

The four commands are the same documents, so a project whose agent
cannot speak MCP loses none of them:

```console
docker compose exec app vendor/bin/kinetis orbitron:context
docker compose exec app vendor/bin/kinetis orbitron:inspect
docker compose exec app vendor/bin/kinetis orbitron:verify
docker compose exec app vendor/bin/kinetis orbitron:scaffold
```

Write the same handshake into `AGENTS.md` in terms of those commands and
their exit codes. The contract is the requirement; MCP is one way to
satisfy it. Neither installed-source tool has a command counterpart: a
shell-only agent, or a project without Orbitron's MCP server registered,
reads and searches `vendor/<vendor>/<package>` directly with its own
file tools, or runs `composer show kinetis/<package>` for an installed
Kinetis version.

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

Orbitron reads three things on this machine: Composer's
installed-package metadata, the project's own `composer.json`, and — for
`orbitron:scaffold` — the existence and symlink state of four fixed
directories and two fixed paths. Over MCP only, it also reads a bounded
line window of one real installed, non-root package's own
`composer.json`, `README.md`, or a file beneath `src/`, `bin/` or
`resources/`, searches one such file for a literal string, and lists the
direct children of `src/`, `bin/` or `resources/` or of a directory
beneath one — never the Composer root project, meaning the application
itself, or anything outside those admitted locations. No application
source, no configuration, no credentials. It starts no process.

It writes two files: the scaffold's fixed targets, only on
`orbitron:scaffold --apply` or `orbitron_scaffold_apply`, each through a
create that refuses an occupied path and never truncates one. That is the
whole write set. Neither a command line nor an MCP message supplies a
path to either write target — the root comes from
`Kinetis\Runtime\ProjectRoot::detect()` and every name below it is a
constant — and nothing creates a directory.
`orbitron_read_package_source`, `orbitron_search_package_source` and
`orbitron_list_package_source` are the only calls that take a
caller-supplied path, and only for reading — admitted against the fixed
locations above, with the resolved target re-admitted, before anything
reaches the filesystem.

One operation leaves this machine, and only over MCP: reading a
`kinetis://docs/*` resource, which `kinetis/mcp-docs` fetches over HTTPS
from its own fixed main-branch origin under the bounds described in [The
documentation resources](#the-documentation-resources). It carries no
credential, sends nothing about the project, and no message can redirect
it: the URL is built from that package's own constants. The four
commands and Orbitron's own seven tools reach no network at all.

The MCP server is a local process the client launches, so that process
and your filesystem permissions are the authority boundary. It has no
shell and no credentials to reach.

The installed metadata is the one generated file
`vendor/composer/installed.php`, so the invocation does touch a file
inside `vendor/`: a command reads it through `Composer\InstalledVersions`,
which loads it itself, and the MCP server evaluates that same fixed path
under the detected project root — no message can name it. Beyond that one
file, the commands and every MCP tool but three reach nothing else under
`vendor/`; only `orbitron_read_package_source`,
`orbitron_search_package_source` and `orbitron_list_package_source` read
further, and only inside the one admitted location — `composer.json`,
`README.md`, `src/`, `bin/` or `resources/` — of the one real
installed, non-root package a call names, as bounded in [Read installed
package source](#read-installed-package-source).

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
- {doc}`mcp-docs` — the package Orbitron composes for the
  `kinetis://docs/*` resources, and the way to serve this documentation
  on its own, without the harness.
- {doc}`cli` — how commands are discovered and what the binary does
  around them.
