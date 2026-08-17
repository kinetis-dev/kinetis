# Storage

````{note}
Not part of core. Install it separately:

```{code-block} sh
composer require kinetis/storage
```
````

File storage against `League\Flysystem`'s `FilesystemOperator` interface —
read, write, delete, and list files through one interface, swappable to a
different backend with no application-code changes. The local backend
runs every operation without blocking the rest of your application. S3
(and S3-compatible services) is a second, equally non-blocking backend —
see {doc}`storage-s3`.

With `FILESYSTEM_DRIVER` set, installing the package is the whole
setup: it binds `FilesystemOperator`, so a controller, command, or
queued job constructor-injects it with nothing to register.

```{code-block} php
use League\Flysystem\FilesystemOperator;
use Psr\Http\Message\UploadedFileInterface;

final readonly class AvatarController
{
    public function __construct(private FilesystemOperator $storage) {}

    #[Post('/avatars')]
    public function store(UploadedFileInterface $avatar): array
    {
        $this->storage->write('avatars/user-42.png', $avatar->getStream()->getContents());

        return ['stored' => true];
    }
}
```

The injected value is a plain `League\Flysystem\FilesystemOperator` —
any existing Flysystem knowledge or tooling applies directly; there's no
Kinetis-specific interface wrapping it.

Build one directly when you need a second, named connection, or when
you are outside the container entirely:

```{code-block} php
use Kinetis\Storage\FilesystemFactory;

$storage = FilesystemFactory::fromConfig($config);
```

## Configuring

```{code-block} text
FILESYSTEM_DRIVER=local
FILESYSTEM_ROOT=/var/app/storage
```

`FILESYSTEM_DRIVER` defaults to `local` — the only driver this package
implements. `FILESYSTEM_ROOT` is required; there's no sane default to
guess, since a wrong one could write files somewhere unintended.

## Named connections

```{code-block} php
$backups = FilesystemFactory::fromConfig($config, 'backups');
```

```{code-block} text
FILESYSTEM_BACKUPS_DRIVER=local
FILESYSTEM_BACKUPS_ROOT=/var/app/backups
```

Following {doc}`config`'s named-connection convention: `'default'` reads
the plain `FILESYSTEM_*` keys above; any other name reads
`FILESYSTEM_{NAME}_*` instead. A named filesystem is never autowired by
type — resolve it explicitly, or construct it directly, wherever it's
needed.

## Streaming an upload

`Dispatcher` already resolves `UploadedFileInterface` parameters (see
{doc}`routing-validation`); its stream feeds `writeStream()` directly:

```{code-block} php
use Kinetis\Http\Attributes\Post;
use League\Flysystem\FilesystemOperator;
use Psr\Http\Message\UploadedFileInterface;

final readonly class AvatarController
{
    public function __construct(private FilesystemOperator $storage) {}

    #[Post('/avatar')]
    public function store(UploadedFileInterface $avatar): array
    {
        $this->storage->writeStream(
            "avatars/{$avatar->getClientFilename()}",
            $avatar->getStream()->detach(),
        );

        return ['status' => 'stored'];
    }
}
```

## Metadata and visibility

```{code-block} php
use League\Flysystem\Visibility;

$storage->fileExists('avatars/user-42.png');
$storage->fileSize('avatars/user-42.png');
$storage->lastModified('avatars/user-42.png');
$storage->mimeType('avatars/user-42.png');

$storage->setVisibility('avatars/user-42.png', Visibility::PUBLIC);
```

`listContents($path, deep: true)` walks a directory tree, yielding
`FileAttributes`/`DirectoryAttributes` for each entry:

```{code-block} php
foreach ($storage->listContents('avatars', deep: true) as $item) {
    $item->path();
    $item->isFile();
}
```

## See also

- {doc}`storage-s3` — the S3-backed driver, plugged in via the same
  `FilesystemFactory`.
- {doc}`config` — the named-connection convention `FilesystemFactory`
  builds on.
- {doc}`persistence` — MySQL, Postgres, and Redis, which run the same
  way: without blocking the rest of your application.
- {doc}`routing-validation` — `UploadedFileInterface` parameter binding.
