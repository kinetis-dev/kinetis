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

Polls the Lambda Runtime API and converts API Gateway v2 payloads to/from
PSR-7, including `multipart/form-data` support — the one piece Lambda
specifically needs that core's `FrankenPhpAdapter`/`FpmAdapter` don't.

There's nothing to configure or call directly: install the package, and
`RuntimeDetector` picks it up automatically the moment `AWS_LAMBDA_RUNTIME_API`
is set in the environment, alongside the two adapters that already ship in
core.

```sh
composer require kinetis/bref-adapter
```

Requires PHP 8.4+ and `kinetis/framework`. See
[Runtime Adapters](https://kinetis.dev/docs/runtime-adapters.html) for how
runtime detection works and what changes under Lambda specifically.

## License

MIT — see [LICENSE](../../LICENSE).
