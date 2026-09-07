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
docker run --rm -v "$PWD":/app -w /app composer:2 \
    create-project --no-install kinetis/skeleton my-app
cd my-app
cp .env.example .env
docker compose up --build
```

Then open [http://localhost:8080](http://localhost:8080). Docker is the
only thing you need — the containers install the dependencies and run
the app, so no PHP or Composer has to exist on the host. (`--no-install`
is what keeps it that way: it fetches the project without resolving
dependencies, which `docker compose up` then does inside the container
it will run them in.)

The project is yours from that point on. `docker-compose.yml` mounts it
at `/app` and needs nothing outside it.

## Using this as a starting point

Start editing what you just created. The whole app is
`src/Http/WelcomeController.php` (one route) and `public/index.php`,
which is the Composer autoloader plus one
`Kinetis\Runtime\HttpStartup::run()` call — the framework owns the
startup program itself. Add your own controllers anywhere under `App\`;
Kinetis discovers them automatically.

Looking for a larger, more realistic example — a database, a queue,
scheduled commands, real-time updates? See
[`kinetis/pingpong`](https://github.com/kinetis-dev/pingpong).

## Working on this package itself

This package is developed in the
[kinetis-dev/kinetis](https://github.com/kinetis-dev/kinetis) monorepo
and published from it; `kinetis-dev/skeleton` is the split mirror the
commands above install from. Inside the monorepo, `composer.json` still
carries the `path` repository that resolves `kinetis/framework` from the
sibling checkout, so the stack needs the override that mounts it:

```sh
docker compose -f docker-compose.yml -f docker-compose.monorepo.yml up --build
```

Container paths are `/app` either way — that override adds mounts and
changes nothing else.

## License

MIT — see [LICENSE](LICENSE).
