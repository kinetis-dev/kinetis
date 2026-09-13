<p align="center">
  <img src="logo.svg" alt="Kinetis" width="420">
</p>

<p align="center">
  <strong>kinetis/views-twig</strong>
  <br>
  <strong>Twig templates for Kinetis</strong>
</p>

<p align="center">
  <a href="https://packagist.org/packages/kinetis/views-twig"><img src="https://img.shields.io/packagist/v/kinetis/views-twig?label=version" alt="Packagist Version"></a>
  <a href="https://packagist.org/packages/kinetis/views-twig"><img src="https://img.shields.io/packagist/dt/kinetis/views-twig" alt="Packagist Downloads"></a>
  <a href="https://packagist.org/packages/kinetis/views-twig"><img src="https://img.shields.io/packagist/php-v/kinetis/views-twig" alt="PHP Version"></a>
  <a href="https://packagist.org/packages/kinetis/views-twig"><img src="https://img.shields.io/packagist/l/kinetis/views-twig" alt="License"></a>
  <a href="https://github.com/kinetis-dev/kinetis/actions/workflows/ci.yml"><img src="https://github.com/kinetis-dev/kinetis/actions/workflows/ci.yml/badge.svg" alt="CI"></a>
</p>

---

Part of [Kinetis](https://kinetis.dev/), a non-blocking PHP framework for
API-first applications, developed in the
[kinetis-dev/kinetis](https://github.com/kinetis-dev/kinetis) monorepo.

[Twig](https://twig.symfony.com/) templates for
[`kinetis/views`](https://github.com/kinetis-dev/views). It conflicts with the
pure PHP and Latte adapters so an application has one unambiguous view engine.

```php
$app->instance(Views::class, new Views(new TwigViewEngine(
    __DIR__ . '/resources/views',
    new AssetUrl('/'),
    options: ['cache' => __DIR__ . '/var/cache/twig', 'auto_reload' => false],
)));
```

See the [views documentation](https://kinetis.dev/docs/views.html) for cache,
extension, and persistent-worker guidance.

## License

MIT — see [LICENSE](LICENSE).
