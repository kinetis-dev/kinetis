<p align="center">
  <img src="logo.svg" alt="Kinetis" width="420">
</p>

<p align="center">
  <strong>kinetis/storage</strong>
  <br>
  <strong>File storage for Kinetis, built on <code>League\Flysystem</code></strong>
</p>

<p align="center">
  <a href="https://packagist.org/packages/kinetis/storage"><img src="https://img.shields.io/packagist/v/kinetis/storage" alt="Packagist Version"></a>
  <a href="https://packagist.org/packages/kinetis/storage"><img src="https://img.shields.io/packagist/dt/kinetis/storage" alt="Packagist Downloads"></a>
  <a href="https://packagist.org/packages/kinetis/storage"><img src="https://img.shields.io/packagist/php-v/kinetis/storage" alt="PHP Version"></a>
  <a href="https://packagist.org/packages/kinetis/storage"><img src="https://img.shields.io/packagist/l/kinetis/storage" alt="License"></a>
  <a href="https://github.com/kinetis-dev/kinetis/actions/workflows/ci.yml"><img src="https://github.com/kinetis-dev/kinetis/actions/workflows/ci.yml/badge.svg" alt="CI"></a>
</p>

---

Read, write, delete, and list files against `League\Flysystem`'s
`FilesystemOperator` interface — swappable to a different backend with no
application-code changes. Local storage is Kinetis's own backend,
`Amp\File`-backed rather than Flysystem's own local adapter, so every
operation genuinely suspends the calling Fiber instead of blocking the
whole worker process. Remote backends (S3, etc.) live in the separate
`kinetis/storage-s3`.

```php
use Kinetis\Storage\FilesystemFactory;

$storage = FilesystemFactory::fromConfig($config);

$storage->write('avatars/user-42.png', $imageContents);
$contents = $storage->read('avatars/user-42.png');
$storage->delete('avatars/user-42.png');
```

## Installation

```sh
composer require kinetis/storage
```

Requires PHP 8.4+ and `kinetis/framework`. Full documentation:
[docs.kinetis.dev/storage.html](https://docs.kinetis.dev/storage.html).

## License

MIT — see [LICENSE](../../LICENSE).
