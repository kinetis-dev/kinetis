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

## Symlink checks — and why they are not a security boundary

A symlink anywhere below `FILESYSTEM_ROOT`, pointing anywhere, would
otherwise let a caller escape it: `read()`ing through a directory
symlink returns whatever the link points at, `write()`ing through one
lands the file wherever the link points, and recursively deleting a
directory containing one would delete through it too. The local driver
(`AmpFileAdapter`) checks for this: every path is checked one component
at a time, from directly under the root down to the target, with a
check that inspects the component itself and never follows it — a
directory listing applies the identical check to every entry it
discovers, which is also what stops it from looping forever on a
symlink that points back into itself. Any component that turns out to
be a symlink throws `League\Flysystem\SymbolicLinkEncountered`, the same
exception Flysystem's own reference local adapter uses for this:

```{code-block} php
use League\Flysystem\SymbolicLinkEncountered;

try {
    $storage->read('uploads/avatar.png');
} catch (SymbolicLinkEncountered $e) {
    // $e->location() names the path that turned out to be a symlink.
}
```

`fileExists()`/`directoryExists()` are the one exception to throwing:
since they already report "no" for anything else that isn't really
there, a path through a symlink reports `false` rather than raising an
exception a caller checking mere existence wouldn't expect.

**This is not a race-free guarantee, and `FILESYSTEM_ROOT` is not a
security boundary against a concurrent actor.** Stated plainly here
rather than as a footnote below, because "symlinks are never followed"
would be a false claim: each check above is a real filesystem call
(lstat), separate from the real read/write/delete that follows it a few
instructions later, and nothing enforces that nothing changes in
between.

**What the checks catch**: a symlink that already exists below
`FILESYSTEM_ROOT` at the moment a path component is checked, however it
got there — an unpacked archive that contained one, a link left over
from an earlier operation, one planted moments before the current
request and left in place. This covers the ordinary case of untrusted
content landing on disk (an unpacked upload, for one) and later being
read back through `$storage`. Real, but bounded: this is a check-then-use
guard, not a race-free primitive.

**What they cannot catch, structurally**: a symlink swapped into place
between a component's own check and the real operation that follows it.
Checking a deeper path component doesn't help — by the time the real
operation runs, it resolves the whole path fresh, following whatever the
swapped component has become by then, regardless of what an earlier
check found. The check and the use are always two separate syscalls
with an unavoidable gap between them; closing that for real needs a
directory-relative, no-follow open (`openat()`/`O_NOFOLLOW`, walked one
component at a time from a held parent directory descriptor), which
neither `Amp\File` nor PHP itself exposes without a native extension.
`ext-ffi` — the route to binding it directly — was checked rather than
assumed absent: it isn't compiled into this project's own standard
`php:8.4-cli-alpine` toolchain image, and even where it is available,
taking on a native extension dependency to reach one syscall is a
heavier, more fragile commitment than the risk it would close. Not
pursued.

**The supported threat model, narrowed rather than left open-ended**:
`FILESYSTEM_ROOT` is a real boundary only when this adapter is the sole
writer to it — an application-exclusive directory nothing else, trusted
or not, creates, renames, or replaces entries in concurrently. Outside
that model — shared storage, a process unpacking untrusted uploads
directly into `FILESYSTEM_ROOT` while `$storage` also serves requests
against it, any other actor with concurrent write access to the tree —
these checks provide no protection at all, not merely weaker protection:
winning the race needs nothing beyond ordinary filesystem access to
`FILESYSTEM_ROOT`, not an already-compromised environment. A deployment
that can't guarantee exclusive access needs an OS-level control this
adapter cannot provide from PHP instead: Linux's `nosymfollow` mount
option (5.10+), a dedicated bind-mount or mount namespace with no
symlink-creation rights for any other writer, or restricting
`symlink()` for every other writer via a seccomp/LSM profile.

## Recursive deletion: all-or-nothing on a symlink, not on an I/O failure

`deleteDirectory()` walks the whole subtree first and only then deletes
anything — a symlink found anywhere in the tree throws
`SymbolicLinkEncountered` before a single file or directory has been
removed, rather than partway through a combined walk-and-delete pass
that would leave every safe sibling visited earlier already gone. This
guarantee covers the symlink-policy case specifically; a failure partway
through the actual deletion (a permission error deleting one file, for
one) is not made atomic by it — nothing short of a real filesystem
transaction could make an I/O failure mid-deletion undo what already
succeeded, so a caller catching a `FilesystemException` here (as opposed
to `SymbolicLinkEncountered`) should expect the tree to be partially
deleted, not intact.

## See also

- {doc}`storage-s3` — the S3-backed driver, plugged in via the same
  `FilesystemFactory`.
- {doc}`config` — the named-connection convention `FilesystemFactory`
  builds on.
- {doc}`persistence` — MySQL, Postgres, and Redis, which run the same
  way: without blocking the rest of your application.
- {doc}`routing-validation` — `UploadedFileInterface` parameter binding.
