<p align="center">
  <img src="logo.svg" alt="Kinetis" width="420">
</p>

<p align="center">
  <strong>kinetis/views-php</strong>
  <br>
  <strong>Pure PHP templates for Kinetis</strong>
</p>

<p align="center">
  <a href="https://packagist.org/packages/kinetis/views-php"><img src="https://img.shields.io/packagist/v/kinetis/views-php?label=version" alt="Packagist Version"></a>
  <a href="https://packagist.org/packages/kinetis/views-php"><img src="https://img.shields.io/packagist/dt/kinetis/views-php" alt="Packagist Downloads"></a>
  <a href="https://packagist.org/packages/kinetis/views-php"><img src="https://img.shields.io/packagist/php-v/kinetis/views-php" alt="PHP Version"></a>
  <a href="https://packagist.org/packages/kinetis/views-php"><img src="https://img.shields.io/packagist/l/kinetis/views-php" alt="License"></a>
  <a href="https://github.com/kinetis-dev/kinetis/actions/workflows/ci.yml"><img src="https://github.com/kinetis-dev/kinetis/actions/workflows/ci.yml/badge.svg" alt="CI"></a>
</p>

---

Part of [Kinetis](https://kinetis.dev/), a non-blocking PHP framework for
API-first applications, developed in the
[kinetis-dev/kinetis](https://github.com/kinetis-dev/kinetis) monorepo.

Pure PHP templates for [`kinetis/views`](https://github.com/kinetis-dev/views).
It conflicts with the Latte and Twig adapters so an application has one
unambiguous view engine.

```php
$app->instance(Views::class, new Views(
    new PhpViewEngine(__DIR__ . '/resources/views', new AssetUrl('/')),
));
```

Templates use ordinary PHP and receive an invokable `$asset` variable. See the
[views documentation](https://kinetis.dev/docs/views.html) for the full
contract and escaping guidance.

## License

MIT — see [LICENSE](LICENSE).
