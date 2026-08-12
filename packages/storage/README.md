<p align="center">
  <img src="logo.svg" alt="Kinetis" width="420">
</p>

<p align="center">
  <strong>kinetis/storage</strong>
  <br>
  <strong>File storage for Kinetis, built on <code>League\Flysystem</code></strong>
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

Requires PHP 8.4+ and `kinetis/kinetis`. Full documentation:
[docs.kinetis.dev/storage.html](https://docs.kinetis.dev/storage.html).

## License

MIT — see [LICENSE](../../LICENSE).
