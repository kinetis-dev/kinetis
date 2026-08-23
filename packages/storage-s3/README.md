<p align="center">
  <img src="logo.svg" alt="Kinetis" width="420">
</p>

<p align="center">
  <strong>kinetis/storage-s3</strong>
  <br>
  <strong>S3 (and S3-compatible) file storage for Kinetis, plugging into kinetis/storage's <code>FILESYSTEM_DRIVER=s3</code></strong>
</p>

<p align="center">
  <a href="https://packagist.org/packages/kinetis/storage-s3"><img src="https://img.shields.io/packagist/v/kinetis/storage-s3?label=version" alt="Packagist Version"></a>
  <a href="https://packagist.org/packages/kinetis/storage-s3"><img src="https://img.shields.io/packagist/dt/kinetis/storage-s3" alt="Packagist Downloads"></a>
  <a href="https://packagist.org/packages/kinetis/storage-s3"><img src="https://img.shields.io/packagist/php-v/kinetis/storage-s3" alt="PHP Version"></a>
  <a href="https://packagist.org/packages/kinetis/storage-s3"><img src="https://img.shields.io/packagist/l/kinetis/storage-s3" alt="License"></a>
  <a href="https://github.com/kinetis-dev/kinetis/actions/workflows/ci.yml"><img src="https://github.com/kinetis-dev/kinetis/actions/workflows/ci.yml/badge.svg" alt="CI"></a>
</p>

---

The same `League\Flysystem\FilesystemOperator` interface `kinetis/storage`
gives local disk, backed by `AsyncAws\S3\S3Client` and
`League\Flysystem\AsyncAwsS3\AsyncAwsS3Adapter` instead — genuinely
non-blocking, via `kinetis/revolt-http-client`'s Revolt-native HTTP
transport injected into `S3Client`, not the SDK's default blocking one.

```php
use Kinetis\Storage\FilesystemFactory;

$storage = FilesystemFactory::fromConfig($config); // FILESYSTEM_DRIVER=s3

$storage->write('avatars/user-42.png', $imageContents);
$contents = $storage->read('avatars/user-42.png');
$storage->delete('avatars/user-42.png');
```

## Configuration

```
FILESYSTEM_DRIVER=s3
FILESYSTEM_S3_BUCKET=my-app-bucket
FILESYSTEM_S3_REGION=us-east-1
```

| Key | Default | Purpose |
|---|---|---|
| `FILESYSTEM_S3_BUCKET` | *(required)* | Bucket name. |
| `FILESYSTEM_S3_REGION` | *(required)* | AWS region. |
| `FILESYSTEM_S3_PREFIX` | — | Key prefix. |
| `FILESYSTEM_S3_ENDPOINT` | — | S3-compatible endpoint instead of real AWS (e.g. MinIO). |
| `FILESYSTEM_S3_PATH_STYLE` | `false` | Path-style addressing — `true` for MinIO and most other non-AWS S3-compatible services. |

Every key is scoped — `FILESYSTEM_S3_BUCKET` + `backups` →
`FILESYSTEM_BACKUPS_S3_BUCKET`. Full reference:
[kinetis.dev/docs/config.html](https://kinetis.dev/docs/config.html).

Credentials are never read from Kinetis config — `AsyncAws\Core\Configuration`
resolves them on its own, from `AWS_ACCESS_KEY_ID`/`AWS_SECRET_ACCESS_KEY`
or an IAM role, the standard AWS SDK convention.

## Installation

```sh
composer require kinetis/storage-s3
```

Requires PHP 8.4+, `kinetis/framework`, and `kinetis/revolt-http-client`.
Full documentation:
[kinetis.dev/docs/storage-s3.html](https://kinetis.dev/docs/storage-s3.html).

## License

MIT — see [LICENSE](LICENSE).
