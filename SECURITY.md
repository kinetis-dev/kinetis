# Security Policy

## Reporting a Vulnerability

Please report security vulnerabilities privately — **not** as a public
GitHub issue.

Go to the [Security tab](https://github.com/kinetis-dev/kinetis/security/advisories/new)
and click "Report a vulnerability." This opens a private advisory
thread visible only to you and the maintainers, with:

- A description of the vulnerability and its impact
- Steps to reproduce, or a proof-of-concept
- The affected package(s) and version(s) — see "Scope" below
- Whether you're aware of it being exploited in the wild

You'll get an acknowledgment within **3 business days**. We'll keep you
updated as we investigate and fix the issue, and credit you in the
advisory and release notes unless you'd prefer to stay anonymous.

## Scope

Kinetis is a monorepo of independently-versioned packages
(`kinetis/framework`, `kinetis/persistence`, `kinetis/queue`, and
others under `kinetis-dev/*` on Packagist). A report against any of
them is in scope — including `kinetis/skeleton` and `kinetis/pingpong`.
If you're not sure which package a bug lives in, report it anyway —
we'll route it internally.

## Supported Versions

Only the **latest released minor** of each package receives security
fixes. Kinetis is pre-1.0 in spirit even where a package's version
number has crossed 1.0 — expect the fix to land as a new patch release
on the current minor line, not backported indefinitely.

Concretely, for any package: the newest minor line published on
Packagist is supported, and every minor line below it is not. No version
number is written here on purpose — packages release often enough that
one would be wrong within a week, and
[packagist.org/packages/kinetis/](https://packagist.org/packages/kinetis/)
always shows what is current.

If a vulnerability affects multiple packages (a shared dependency, a
contract both sides implement), we'll coordinate fixes across all of
them before disclosure.

## Disclosure

We follow coordinated disclosure: once a fix is released, we publish a
GitHub Security Advisory describing the issue, affected versions, and
the fixed version. We ask reporters not to disclose publicly until a
fix has shipped, and we aim to ship within **90 days** of a confirmed
report (usually much sooner for anything with a working exploit).

## What's Out of Scope

- Vulnerabilities in third-party dependencies — report those upstream;
  we'll track and update once a fix is available.
- Findings that require an already-compromised environment or admin
  access to exploit.

## Known Accepted Risks

Some residual risk is accepted by deliberate decision rather than left
as an oversight. These are documented, not hidden, and a report against
one of them will be closed as expected behavior unless it demonstrates a
way past the stated threat model.

- **`kinetis/storage`'s local driver (`AmpFileAdapter`) rejects a
  symlink below `FILESYSTEM_ROOT` with a check-then-use guard, not a
  race-free one.** This is *not* covered by the "already-compromised
  environment" carve-out above — exploiting the gap between the check
  and the real operation needs only ordinary, concurrent write access to
  `FILESYSTEM_ROOT` itself (a lower-trust co-tenant process, an unpack
  step sharing the same directory, ...), not a compromised environment
  in any other sense. The supported threat model is narrower than that:
  `FILESYSTEM_ROOT` is a real boundary only when this adapter is the
  sole writer to it. See
  [docs.kinetis.dev/storage](https://docs.kinetis.dev/storage.html)'s
  symlink-checks section for the full decision and the operational
  mitigation for a deployment that can't guarantee exclusive access.
