# Storage

````{note}
Not part of core. Install it separately:

```{code-block} sh
composer require kinetis/storage
```
````

`kinetis/storage` gives an application one
`League\Flysystem\FilesystemOperator` for writing, reading, listing and
deleting files. The local driver runs on `Amp\File`, so a call suspends
the calling Fiber instead of blocking the worker. {doc}`storage-s3` puts
S3 behind the same interface, and {doc}`appendix-storage` holds the
contracts behind every rule on this page.

## Configure and inject

```{code-block} text
:caption: .env
FILESYSTEM_DRIVER=local
FILESYSTEM_ROOT=/var/app/storage
```

With `FILESYSTEM_DRIVER` set, the package binds `FilesystemOperator`, so
a controller, command or service constructor-injects it with nothing to
register. With the key unset, the package binds nothing.
`FILESYSTEM_ROOT` is required and must be non-empty, and every path the
application passes is relative to it.

The binding builds one instance per worker process or thread and keeps
it for the application's lifetime. Each instance owns its own `Amp\File`
driver, which without `ext-uv` or `ext-eio` is a pool of up to eight
worker processes. Install either extension in production to replace the
pool with an OS-native driver.

## Write, read, list and delete

```{code-block} php
use League\Flysystem\FilesystemOperator;

final readonly class MonthlyReports
{
    public function __construct(private FilesystemOperator $storage) {}

    public function save(\DateTimeImmutable $month, string $csv): void
    {
        $this->storage->write(self::path($month), $csv);
    }

    public function find(\DateTimeImmutable $month): ?string
    {
        $path = self::path($month);

        return $this->storage->fileExists($path) ? $this->storage->read($path) : null;
    }

    /** @return list<string> */
    public function paths(): array
    {
        $paths = [];

        foreach ($this->storage->listContents('reports', deep: true) as $item) {
            if ($item->isFile()) {
                $paths[] = $item->path();
            }
        }

        return $paths;
    }

    public function remove(\DateTimeImmutable $month): void
    {
        $this->storage->delete(self::path($month));
    }

    private static function path(\DateTimeImmutable $month): string
    {
        return 'reports/' . $month->format('Y-m') . '.csv';
    }
}
```

`write()` creates missing parent directories and replaces an existing
file. `read()` of a file that does not exist throws `UnableToReadFile`.
`fileSize()`, `lastModified()`, `mimeType()`, `copy()`, `move()`,
`createDirectory()` and `deleteDirectory()` complete the set. The
injected value is plain Flysystem, so its documentation applies
directly.

A write requesting no visibility keeps the mode of the file it replaces,
and a new file gets the umask default. Ask for a private file
explicitly:

```{code-block} php
use League\Flysystem\Config;
use League\Flysystem\Visibility;

$storage->write('exports/payroll.csv', $csv, [
    Config::OPTION_VISIBILITY => Visibility::PRIVATE,
]);
```

`visibility` names the file only. A parent directory created on the way
reads `directory_visibility`; see {doc}`appendix-storage`.

A queued job's constructor holds only data that survives serialization,
so a job receives the filesystem as a class-typed parameter of
`handle()`, which the container resolves when the job runs (see
{doc}`queue`):

```{code-block} php
use Kinetis\Queue\Job;
use League\Flysystem\FilesystemOperator;

final readonly class ArchiveExport implements Job
{
    public function __construct(public string $path) {}

    public function handle(FilesystemOperator $storage): void
    {
        $storage->move($this->path, 'archive/' . basename($this->path));
    }
}
```

## Handle an upload

An upload carries two labels the client wrote: its media type and its
filename. Neither is evidence of what the bytes are. The route below
bounds the size, detects the type from the content, and generates the
storage key itself:

```{code-block} php
use Kinetis\Http\Attributes\Post;
use Kinetis\Http\Responses\ErrorResponse;
use Kinetis\Validation\Constraints\FileSize;
use League\Flysystem\FilesystemOperator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UploadedFileInterface;

final readonly class AvatarController
{
    /** Accepted formats, keyed by the type detected from the content. */
    private const array EXTENSIONS = [
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
    ];

    public function __construct(private FilesystemOperator $storage) {}

    #[Post('/avatars')]
    public function store(
        #[FileSize(maxBytes: 2_000_000, minBytes: 1)]
        UploadedFileInterface $avatar,
    ): ResponseInterface|array {
        $contents = (string) $avatar->getStream();
        $detected = (new \finfo(\FILEINFO_MIME_TYPE))->buffer($contents);
        $extension = self::EXTENSIONS[$detected] ?? null;

        if ($extension === null) {
            return ErrorResponse::create(422, 'The avatar must be a PNG or JPEG image.');
        }

        $path = 'avatars/' . bin2hex(random_bytes(16)) . '.' . $extension;
        $this->storage->write($path, $contents);

        return ['path' => $path];
    }
}
```

- **Size.** `#[FileSize]` refuses a part outside its bounds with a `422`
  before the handler runs (see {doc}`routing-validation`). The request
  body has already been received and staged, so `write()` with the
  contents is the direct call.
- **Type.** `finfo` reads the content's signature.
  `getClientMediaType()` repeats whatever the client sent and never
  selects the stored format. `ext-fileinfo` is required by
  `league/mime-type-detection`, a Flysystem dependency, so it is present
  wherever this package is installed.
- **Name.** The key is generated. A client filename is never a path
  segment: it can collide with another user's object, name a dotfile, or
  carry a segment the path rules refuse. Keep the original name as data
  if the application displays it, and escape it on output.

A detected signature names a format; it does not make the rest of the
file harmless. Keep uploads out of any directory a web server executes
or serves directly, and re-encode images before showing them to other
users.

## Local disk or S3

Use `local` when every worker serving the application runs on one host
or mounts the same volume. Use `s3` (see {doc}`storage-s3`) when workers
run on several hosts without a shared volume, or on AWS Lambda, where
local disk does not outlive the execution environment. Application code
is identical for both; only configuration changes.

Both can run side by side. `'default'` reads the plain `FILESYSTEM_*`
keys, and any other connection name reads `FILESYSTEM_{NAME}_*`:

```{code-block} text
:caption: .env
FILESYSTEM_BACKUPS_DRIVER=s3
FILESYSTEM_BACKUPS_S3_BUCKET=my-app-backups
FILESYSTEM_BACKUPS_S3_REGION=eu-west-1
```

```{code-block} php
use Kinetis\Storage\FilesystemFactory;

$backups = FilesystemFactory::fromConfig($config, 'backups');
```

A named connection is never autowired. Register it once in
`bootstrap.php` and keep it for the application's lifetime rather than
building one per request — {doc}`appendix-configuration` shows the
registration.

## Rules that affect application code

- **Paths stay inside the root.** A path that climbs above the root
  throws `League\Flysystem\PathTraversalDetected`, a control character
  throws `CorruptedPathDetected`, and a symlink below the root throws
  `SymbolicLinkEncountered` (`fileExists()` and `directoryExists()`
  answer `false` instead); in each case the operation does not run. An
  empty key names the root itself, and writing to it throws
  `UnableToWriteFile`. The root is a hard boundary only while this
  adapter is the sole writer to the tree: a link another writer creates
  during an operation is not detected.
- **Local writes publish complete files.** `write()`, `writeStream()` and
  `copy()` build the content beside the destination and rename it into
  place, so a reader never sees a short file. Nothing is `fsync`ed; a
  crash or power loss can leave either version.
- **A failed write may have happened.** An `UnableToWriteFile` or
  `UnableToCopyFile` raised by that final rename can follow a rename
  that took effect, so the destination may hold either file. Retry only
  where this code is the sole writer to that path. S3 requests carry the
  same uncertainty.
- **`deleteDirectory()` is not atomic.** On local disk a symlink anywhere
  in the tree stops it before anything is deleted; any other failure can
  leave the tree partially deleted.
- **Prefer `read()` and `write()`.** `readStream()` buffers the whole
  file before returning, and `writeStream()` reads the caller's resource
  on the calling thread without closing it. Both block the thread on
  disk I/O.
- **One exception family.** Every failure implements
  `League\Flysystem\FilesystemException`. Each operation reports its own
  `UnableTo*` type, and a refused path keeps its own type.

## Common errors

| Error | Cause | Fix |
|---|---|---|
| The container cannot resolve `FilesystemOperator` | `FILESYSTEM_DRIVER` is unset | Set `FILESYSTEM_DRIVER`. |
| `Kinetis\Config\Exception\MissingConfigException` naming `FILESYSTEM_ROOT` | The local driver has no root | Set `FILESYSTEM_ROOT`. |
| `InvalidArgumentException`: "A storage root is required" | `FILESYSTEM_ROOT=` is empty | Set a non-empty path; `/` is valid. |
| `InvalidArgumentException`: "… is not supported by kinetis/storage" | Unknown `FILESYSTEM_DRIVER` value | Use `local` or `s3`. |
| `Kinetis\Storage\Exception\StorageUnavailableException` | `FILESYSTEM_DRIVER=s3` without the S3 package | `composer require kinetis/storage-s3`. |
| `PathTraversalDetected` or `CorruptedPathDetected` | A path built from request input | Generate storage keys on the server. |
| `SymbolicLinkEncountered`, or `UnableToListContents` wrapping it | A symlink inside `FILESYSTEM_ROOT` | Replace the link with a real directory. The configured root itself may be a symlink. |
| `UnableToWriteFile`: "the destination names the storage root itself" | An empty or root-only key | Build the key before writing. |

## See also

- {doc}`appendix-storage` — publication, path and symlink contracts, the
  per-operation exception table, and S3 failure semantics.
- {doc}`storage-s3` — the S3 driver.
- {doc}`routing-validation` — `UploadedFileInterface` binding and the
  `#[FileSize]` and `#[FileExtension]` rules.
- {doc}`appendix-configuration` — every `FILESYSTEM_*` key and the
  named-connection convention.
