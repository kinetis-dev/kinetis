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

Part of [Kinetis](https://kinetis.dev/), a non-blocking PHP framework for
API-first applications, developed in the
[kinetis-dev/kinetis](https://github.com/kinetis-dev/kinetis) monorepo.

The same `League\Flysystem\FilesystemOperator` interface [`kinetis/storage`](https://github.com/kinetis-dev/storage)
gives local disk, backed by `League\Flysystem\AsyncAwsS3\AsyncAwsS3Adapter`
over an AsyncAws S3 client instead. Every call travels on
[`kinetis/revolt-http-client`](https://github.com/kinetis-dev/revolt-http-client)'s Revolt-native HTTP
transport, injected in place of the SDK's default blocking one, so it
suspends the calling Fiber.

Objects are private and writes and copies carry no ACL, so a bucket with
Object Ownership set to bucket owner enforced works unchanged; grant
public read through a bucket policy. The failures S3 reports under HTTP 200 — a
broken copy, a batch delete that refused keys — are read rather than
taken as success.

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
| `FILESYSTEM_S3_ENDPOINT` | — | S3-compatible endpoint instead of AWS (e.g. MinIO) — one origin, addressed path-style. |
| `FILESYSTEM_S3_PLAINTEXT` | `false` | Allow an `http://` endpoint. |
| `FILESYSTEM_S3_TIMEOUT` | `60` | Seconds per S3 request — connect, idle and transfer alike, not one deadline per operation. |

Every key is scoped — `FILESYSTEM_S3_BUCKET` + `backups` →
`FILESYSTEM_BACKUPS_S3_BUCKET`. Full reference:
[kinetis.dev/docs/config.html](https://kinetis.dev/docs/config.html).

Credentials are never read from Kinetis config — AsyncAws's standard
provider chain resolves them on its own, the usual AWS SDK convention.
Every provider in that chain that calls AWS uses the same Revolt
transport as the client, while the shared credentials and config files
and any token file are read with native blocking calls. Full detail:
[kinetis.dev/docs/storage-s3.html](https://kinetis.dev/docs/storage-s3.html).

## Installation

```sh
composer require kinetis/storage-s3
```

Requires PHP 8.4+, [`kinetis/framework`](https://github.com/kinetis-dev/framework),
[`kinetis/storage`](https://github.com/kinetis-dev/storage), and
[`kinetis/revolt-http-client`](https://github.com/kinetis-dev/revolt-http-client).
Full documentation:
[kinetis.dev/docs/storage-s3.html](https://kinetis.dev/docs/storage-s3.html).

## License

MIT — see [LICENSE](LICENSE).
