<p align="center">
  <img src="logo.svg" alt="Kinetis" width="420">
</p>

<p align="center">
  <strong>kinetis/bref-adapter</strong>
  <br>
  <strong>AWS Lambda (Bref) runtime adapter for Kinetis</strong>
</p>

<p align="center">
  <a href="https://packagist.org/packages/kinetis/bref-adapter"><img src="https://img.shields.io/packagist/v/kinetis/bref-adapter?label=version" alt="Packagist Version"></a>
  <a href="https://packagist.org/packages/kinetis/bref-adapter"><img src="https://img.shields.io/packagist/dt/kinetis/bref-adapter" alt="Packagist Downloads"></a>
  <a href="https://packagist.org/packages/kinetis/bref-adapter"><img src="https://img.shields.io/packagist/php-v/kinetis/bref-adapter" alt="PHP Version"></a>
  <a href="https://packagist.org/packages/kinetis/bref-adapter"><img src="https://img.shields.io/packagist/l/kinetis/bref-adapter" alt="License"></a>
  <a href="https://github.com/kinetis-dev/kinetis/actions/workflows/ci.yml"><img src="https://github.com/kinetis-dev/kinetis/actions/workflows/ci.yml/badge.svg" alt="CI"></a>
</p>

---

Part of [Kinetis](https://kinetis.dev/), a non-blocking PHP framework for
API-first applications, developed in the
[kinetis-dev/kinetis](https://github.com/kinetis-dev/kinetis) monorepo.

Polls the Lambda Runtime API and converts API Gateway v2 payloads to/from
PSR-7, including `multipart/form-data` support — the one piece Lambda
specifically needs that core's `FrankenPhpAdapter`/`FpmAdapter` don't.

The request's identity is rebuilt from one authoritative field each —
`requestContext.domainName` for the host, the forwarded headers for the
scheme and port, `rawPath`/`rawQueryString` for the request target — so
the URI, the `Host` header and the request target cannot disagree; an
event where they do is reported as an invocation error rather than
dispatched. Form bodies are held to `Kinetis\Http\Form\FormLimits`, the
same ceilings core applies under every other runtime, and to
`Kinetis\Http\Form\MultipartEnvelope`'s wire-level multipart contract,
which runs before `riverline/multipart-parser` expands anything. That
matters more here than anywhere else: there is no SAPI at all, so the
framework's own contract is the whole defense. An event whose header map names one header under two
spellings is refused too — an ambiguity resolved by key order is not an
identity anything downstream can rely on.

There's nothing to configure or call directly: install the package, and
`RuntimeDetector` picks it up automatically the moment `AWS_LAMBDA_RUNTIME_API`
is set in the environment, alongside the two adapters that already ship in
core.

```sh
composer require kinetis/bref-adapter
```

Requires PHP 8.4+ and [`kinetis/framework`](https://github.com/kinetis-dev/framework). See
[Runtime Adapters](https://kinetis.dev/docs/runtime-adapters.html) for how
runtime detection works, what changes under Lambda specifically, the
form-body contract's own numbers, and exactly which event fields are
validated and how a malformed one is reported.

## License

MIT — see [LICENSE](LICENSE).
