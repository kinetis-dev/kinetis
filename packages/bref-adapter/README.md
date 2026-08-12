<p align="center">
  <img src="logo.svg" alt="Kinetis" width="420">
</p>

<p align="center">
  <strong>kinetis/bref-adapter</strong>
  <br>
  <strong>AWS Lambda (Bref) runtime adapter for Kinetis</strong>
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
[Runtime Adapters](https://docs.kinetis.dev/runtime-adapters.html) for how
runtime detection works and what changes under Lambda specifically.

## License

MIT — see [LICENSE](../../LICENSE).
