<p align="center">
  <img src="logo.svg" alt="Kinetis" width="420">
</p>

<p align="center">
  <strong>kinetis/views</strong>
  <br>
  <strong>Engine-neutral view rendering for Kinetis</strong>
</p>

<p align="center">
  <a href="https://packagist.org/packages/kinetis/views"><img src="https://img.shields.io/packagist/v/kinetis/views?label=version" alt="Packagist Version"></a>
  <a href="https://packagist.org/packages/kinetis/views"><img src="https://img.shields.io/packagist/dt/kinetis/views" alt="Packagist Downloads"></a>
  <a href="https://packagist.org/packages/kinetis/views"><img src="https://img.shields.io/packagist/php-v/kinetis/views" alt="PHP Version"></a>
  <a href="https://packagist.org/packages/kinetis/views"><img src="https://img.shields.io/packagist/l/kinetis/views" alt="License"></a>
  <a href="https://github.com/kinetis-dev/kinetis/actions/workflows/ci.yml"><img src="https://github.com/kinetis-dev/kinetis/actions/workflows/ci.yml/badge.svg" alt="CI"></a>
</p>

---

Part of [Kinetis](https://kinetis.dev/), a non-blocking PHP framework for
API-first applications, developed in the
[kinetis-dev/kinetis](https://github.com/kinetis-dev/kinetis) monorepo.

The engine-neutral view contract for [Kinetis](https://kinetis.dev/).
Controllers inject `Kinetis\Views\Views` and render an extensionless logical
name; an application chooses one of `kinetis/views-php`,
`kinetis/views-latte`, or `kinetis/views-twig` at bootstrap.

```php
return $this->views->response('articles/index', ['articles' => $articles]);
```

The package also provides engine-neutral `views:warm` and `views:clear`
commands; the selected adapter owns the actual cache behavior. See the
[views documentation](https://kinetis.dev/docs/views.html) for setup, the
`asset()` helper, cache deployment, escaping, and persistent-worker guidance.

## License

MIT — see [LICENSE](LICENSE).
