<p align="center">
  <img src="logo.svg" alt="Kinetis" width="420">
</p>

<p align="center">
  <strong>kinetis/views-latte</strong>
  <br>
  <strong>Latte templates for Kinetis</strong>
</p>

<p align="center">
  <a href="https://packagist.org/packages/kinetis/views-latte"><img src="https://img.shields.io/packagist/v/kinetis/views-latte?label=version" alt="Packagist Version"></a>
  <a href="https://packagist.org/packages/kinetis/views-latte"><img src="https://img.shields.io/packagist/dt/kinetis/views-latte" alt="Packagist Downloads"></a>
  <a href="https://packagist.org/packages/kinetis/views-latte"><img src="https://img.shields.io/packagist/php-v/kinetis/views-latte" alt="PHP Version"></a>
  <a href="https://packagist.org/packages/kinetis/views-latte"><img src="https://img.shields.io/packagist/l/kinetis/views-latte" alt="License"></a>
  <a href="https://github.com/kinetis-dev/kinetis/actions/workflows/ci.yml"><img src="https://github.com/kinetis-dev/kinetis/actions/workflows/ci.yml/badge.svg" alt="CI"></a>
</p>

---

Part of [Kinetis](https://kinetis.dev/), a non-blocking PHP framework for
API-first applications, developed in the
[kinetis-dev/kinetis](https://github.com/kinetis-dev/kinetis) monorepo.

[Latte](https://latte.nette.org/) templates for
[`kinetis/views`](https://github.com/kinetis-dev/views). It conflicts with the
pure PHP and Twig adapters so an application has one unambiguous view engine.

```php
$app->instance(Views::class, new Views(new LatteViewEngine(
    __DIR__ . '/resources/views',
    ViewRuntime::fromConfig(__DIR__, $config),
    new AssetUrl('/'),
)));
```

Production templates compile to `.kinetis-cache/views/latte`; development does
not write a disk cache. See the
[views documentation](https://kinetis.dev/docs/views.html) for `views:warm`,
`views:clear`, extension, and persistent-worker guidance.

## License

MIT — see [LICENSE](LICENSE).
