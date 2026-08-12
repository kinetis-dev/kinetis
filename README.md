<p align="center">
  <img src="docs/_static/logo.svg" alt="Kinetis" width="420">
</p>

<p align="center">
  <strong>The Kinetis monorepo</strong>
</p>

---

This repository is the development monorepo for
[Kinetis](https://docs.kinetis.dev/), a non-blocking PHP framework for
API-first applications. It hosts the shared CI/CD pipeline and the
documentation site for every package in the ecosystem.

- **Core framework**: [`packages/framework`](packages/framework) —
  `kinetis/framework`, the framework itself.
- **Satellite packages**: everything else under
  [`packages/`](packages) — `kinetis/auth`, `kinetis/queue`,
  `kinetis/query-builder`, and the rest of the ecosystem, each its own
  independent, installable Composer package.
- **Documentation**: [`docs/`](docs), the Sphinx source for
  [docs.kinetis.dev](https://docs.kinetis.dev/), covering every package
  in one place. Build it locally with:

  ```sh
  docker run --rm -v "$PWD/docs":/app -w /app python:3.12-slim \
      bash -c "pip install -q -r requirements.txt && sphinx-build -M html . _build -W --keep-going"
  ```

  (the same command CI runs — `-W` fails the build on any Sphinx warning,
  not just a hard error).

Each package under `packages/` has its own `README.md`, `composer.json`,
and test suite, and can be worked on independently.

## License

Kinetis is open-sourced under the [MIT license](LICENSE).
