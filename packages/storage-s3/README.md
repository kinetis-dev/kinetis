<p align="center">
  <img src="logo.svg" alt="Kinetis" width="420">
</p>

<p align="center">
  <strong>kinetis/storage-s3</strong>
  <br>
  <strong>S3 (and S3-compatible) file storage for Kinetis, plugging into kinetis/storage's <code>FILESYSTEM_DRIVER=s3</code></strong>
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

## Configuring

```
FILESYSTEM_DRIVER=s3
FILESYSTEM_S3_BUCKET=my-app-bucket
FILESYSTEM_S3_REGION=us-east-1
```

Credentials are never read from Kinetis config — `AsyncAws\Core\Configuration`
resolves them on its own, from `AWS_ACCESS_KEY_ID`/`AWS_SECRET_ACCESS_KEY`
or an IAM role, the standard AWS SDK convention. Optional:
`FILESYSTEM_S3_PREFIX`, `FILESYSTEM_S3_ENDPOINT` (an S3-compatible
service instead of real AWS), `FILESYSTEM_S3_PATH_STYLE` (`true` for
MinIO and most other non-AWS S3-compatible services).

## Installation

```sh
composer require kinetis/storage-s3
```

Requires PHP 8.4+, `kinetis/kinetis`, and `kinetis/revolt-http-client`.
Full documentation:
[docs.kinetis.dev/storage-s3.html](https://docs.kinetis.dev/storage-s3.html).

## License

MIT — see [LICENSE](../../LICENSE).
