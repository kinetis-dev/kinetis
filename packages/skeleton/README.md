<p align="center">
  <img src="logo.svg" alt="Kinetis" width="420">
</p>

<p align="center">
  <strong>kinetis/skeleton</strong>
  <br>
  <strong>The smallest possible runnable Kinetis application</strong>
</p>

<p align="center">
  <a href="https://packagist.org/packages/kinetis/skeleton"><img src="https://img.shields.io/packagist/v/kinetis/skeleton?label=version" alt="Packagist Version"></a>
  <a href="https://packagist.org/packages/kinetis/skeleton"><img src="https://img.shields.io/packagist/dt/kinetis/skeleton" alt="Packagist Downloads"></a>
  <a href="https://packagist.org/packages/kinetis/skeleton"><img src="https://img.shields.io/packagist/php-v/kinetis/skeleton" alt="PHP Version"></a>
  <a href="https://packagist.org/packages/kinetis/skeleton"><img src="https://img.shields.io/packagist/l/kinetis/skeleton" alt="License"></a>
  <a href="https://github.com/kinetis-dev/kinetis/actions/workflows/ci.yml"><img src="https://github.com/kinetis-dev/kinetis/actions/workflows/ci.yml/badge.svg" alt="CI"></a>
</p>

---

Part of [Kinetis](https://kinetis.dev/), a non-blocking PHP framework for
API-first applications, developed in the
[kinetis-dev/kinetis](https://github.com/kinetis-dev/kinetis) monorepo.

One controller, one route, a welcome page — nginx + PHP-FPM, so a code
change takes effect on your very next request with no container
restart. Meant to be copied and grown from, not run as-is.

## Running it

```sh
git clone https://github.com/kinetis-dev/kinetis.git
cd kinetis/packages/skeleton
cp .env.example .env
docker compose up --build
```

This package lives inside the `kinetis` monorepo, not a separate
`kinetis-skeleton` repository — `packages/skeleton/` is exactly the
directory these commands land you in.

Then open [http://localhost:8080](http://localhost:8080). No PHP or
Composer needed on the host — everything, including dependency
installation, runs inside the containers.

## Using this as a starting point

Copy `packages/skeleton/` out into a new project, point its
`composer.json` at a real [`kinetis/framework`](https://github.com/kinetis-dev/framework) install instead of the `path`
repository this monorepo uses internally, and build from there. The
whole app is `src/Http/WelcomeController.php` (one route) and
`public/index.php` (the standard Kinetis entry point) — read both end to
end, then add your own controllers anywhere under `App\`; Kinetis
discovers them automatically.

Looking for a larger, more realistic example — a database, a queue,
scheduled commands, real-time updates? See
[`kinetis/pingpong`](https://github.com/kinetis-dev/pingpong).

## License

MIT — see [LICENSE](LICENSE).
