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
runs on `Amp\File`: a driver call suspends the calling Fiber rather than
blocking the worker. S3 (and S3-compatible services) is the second
backend — see {doc}`storage-s3`.

With `FILESYSTEM_DRIVER` set, installing the package is the whole
setup: it binds `FilesystemOperator`, so a controller or command
constructor-injects it with nothing to register.

```{code-block} php
use Kinetis\Http\Attributes\Post;
use League\Flysystem\FilesystemOperator;
use Psr\Http\Message\UploadedFileInterface;

final readonly class AvatarController
{
    public function __construct(private FilesystemOperator $storage) {}

    #[Post('/avatars')]
    public function store(UploadedFileInterface $avatar): array
    {
        $extension = match ($avatar->getClientMediaType()) {
            'image/png' => 'png',
            'image/jpeg' => 'jpg',
            default => throw new \InvalidArgumentException('Unsupported image type'),
        };

        // The name is generated here. A client-supplied filename is
        // never a path segment: it can collide with another user's
        // object, name a dotfile the server treats specially, or carry
        // a relative segment the normalizer resolves back into the
        // root.
        $path = 'avatars/' . bin2hex(random_bytes(16)) . '.' . $extension;

        $this->storage->write($path, $avatar->getStream()->getContents());

        return ['path' => $path, 'originalName' => $avatar->getClientFilename()];
    }
}
```

`Dispatcher` resolves `UploadedFileInterface` parameters on its own (see
{doc}`routing-validation`), and the body behind one is already buffered
in memory by the time a handler runs, so `write()` is the cheaper call
here. `writeStream()` is for a caller that already holds a resource; it
does not close the one it is given, so close it yourself:

```{code-block} php
$resource = $avatar->getStream()->detach();

try {
    $this->storage->writeStream($path, $resource);
} finally {
    fclose($resource);
}
```

The injected value is a plain `League\Flysystem\FilesystemOperator` —
any existing Flysystem knowledge or tooling applies directly; there's no
Kinetis-specific interface wrapping it.

A queued job takes it differently. A job's constructor holds only the
data that survives being written to the queue and read back by a worker
process, so a `FilesystemOperator` never belongs there — it arrives as a
class-typed parameter of `handle()`, which the container resolves at run
time. See {doc}`queue`.

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

`local` is the only driver this package implements, and
`FilesystemFactory::fromConfig()` falls back to it when
`FILESYSTEM_DRIVER` is absent. The container binding does not: with no
`FILESYSTEM_DRIVER` set, the package registers nothing at all, since
binding a filesystem into every application that merely installed the
package would be guessing at intent. Set the key to get the binding.
`FILESYSTEM_ROOT` is required either way; there's no sane default to
guess, since a wrong one could write files somewhere unintended.

It also has to be non-empty. `FILESYSTEM_ROOT=` is a key that is set,
so it passes the required check, and an empty root would leave every
path relative to whatever working directory the worker process happens
to hold. The adapter refuses it with an `InvalidArgumentException` at
construction rather than confining to a directory nobody configured.
`/` is a legitimate root and stays one.

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

## How the local driver runs

`FilesystemFactory` builds each filesystem on
`Amp\File\createDefaultDriver()`. With `ext-uv` or `ext-eio` loaded that
is an OS-native driver. Without either — the default for a stock PHP
image — it is a pool of up to eight worker *processes*, and every
filesystem call is an IPC round trip to one of them. Either extension
removes the pool; both are listed in the package's `suggest`.

Each `fromConfig()` call builds a driver of its own, and each of those
drivers a pool of its own: one pool per filesystem instance, not one
per event loop. A FrankenPHP image with eight threads therefore holds
up to sixty-four worker processes for one filesystem, and every named
connection built alongside it is another pool again. Build one instance
per process or thread and hold it for the application's lifetime rather
than building one per request — the container binding does exactly
that, and a named connection is worth registering the same way.

`Amp\File\filesystem()`, the library's own shared instance, is not used:
it wraps the driver in a status cache that holds a positive `stat` per
path for 1,000 seconds and invalidates only on mutations made through
that same instance. Under a persistent worker that reports a file
another thread, another process or an external writer has already
deleted or rewritten. `AmpFileAdapter` accepts any
`Amp\File\Filesystem`, so a consumer who wants the cache can construct
one with it.

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

Two options decide a directory's mode, and they are not
interchangeable. `createDirectory()` reads `visibility` first and falls
back to `directory_visibility` — a call naming one directory means that
directory, whichever key it reached for. A parent directory built on the
way to a file by `write()`, `copy()` or `move()` reads only
`directory_visibility`: a `visibility` on a write names the file, and a
private file does not ask for a private tree above it, nor for one that
cuts off the siblings already published there.

```{code-block} php
use League\Flysystem\Config;
use League\Flysystem\Visibility;

// The file is 0600; reports/ is the converter's default for a
// directory, not the file's own mode.
$storage->write('reports/q1.csv', $csv, [
    Config::OPTION_VISIBILITY => Visibility::PRIVATE,
]);

// Both, said separately.
$storage->write('reports/q2.csv', $csv, [
    Config::OPTION_VISIBILITY => Visibility::PRIVATE,
    Config::OPTION_DIRECTORY_VISIBILITY => Visibility::PUBLIC,
]);
```

A call that requests no visibility invents none: replacing a file keeps
the mode that file already had, and a new file lands on the umask
default. `move()` applies an explicit `visibility` through the converter
its source's kind calls for, so moving a directory private lands it on a
directory mode rather than on a file's `0600` with its own contents
unreachable. The conversion happens before a parent is created or
anything is renamed, so an invalid value throws
`InvalidVisibilityProvided` with the tree exactly as it was.

## Writes are published atomically

`write()`, `writeStream()` and `copy()` never build new content at the
destination path. Each one:

1. creates the destination's parent directory if it is missing;
2. creates a directory beside the destination with a random name and
   mode `0700`;
3. creates the new file inside it with an exclusive open;
4. writes the whole body and closes the file;
5. reads the closed file's length back and checks it against the number
   of bytes written to it;
6. applies the mode the file will carry once published;
7. renames it over the destination;
8. removes the staging directory.

```{code-block} php
use League\Flysystem\Config;
use League\Flysystem\Visibility;

// $csv reaches no path a reader outside this service can open.
$storage->write('exports/payroll.csv', $csv, [
    Config::OPTION_VISIBILITY => Visibility::PRIVATE,
]);

// The same for a copy, whether the mode comes from an explicit
// visibility or from the source it is retained from (the default).
$storage->copy('exports/payroll.csv', 'exports/payroll-q1.csv');
```

The rename in step 7 is the commit point, and it is atomic because the
staging directory is a child of the destination's own parent: both paths
are on one filesystem. A concurrent reader opening the destination sees
either the whole old file or the whole new one. Nothing before step 7
touches the destination, so a call that fails before it leaves the
destination exactly as it was.

A failure reported *by* step 7 says less than that. What the adapter
sees is the driver's acknowledgement, and an acknowledgement can go
missing after the kernel has already renamed — a worker process dying
between the two is enough. `UnableToWriteFile` or `UnableToCopyFile`
from the rename therefore means "no success was reported", not "nothing
was published": the destination holds the old file or the new one, and
this adapter cannot tell you which. Read the destination back before
deciding what to do, and do not retry blindly where republishing this
call's body over a *later* update by someone else would be wrong — the
retry writes what this call was given, over whatever is there by then.

The `0700` directory in step 2 is what makes the new file private from
creation: `Amp\File` has no mode argument on opening a file, and the
umask is process-global and cannot be changed safely from a worker
thread. It excludes other Unix users and nothing more — a process
running under the same UID can enter the staging directory. Isolating
writers from each other needs separate UIDs.

Step 5 is there because `Amp\File\File::write()` returns nothing and is
not required to have stored what it accepted: the driver behind a local
file calls `fwrite()` once and only rejects an outright failure, so a
short write against a full disk or a quota returns as though the whole
body landed. Reading the closed file's length back rejects that, and a
length the filesystem cannot report fails the call too. The promise is
length: a destination is never published short.

Cleanup of the staged file and its directory is attempted on every
failure and never allowed to mask the failure being reported, which is
also its limit — a failed cleanup leaves the staged file inside its
`0700` directory, and a cleanup that fails after a committed rename
leaves an empty one. Directories created in step 1 are never rolled
back: another call writing nearby may already be using them.

Durability across a crash is not part of this. Nothing here issues
`fsync(2)` on the file or on the directory, so a process kill, a kernel
panic or a power loss can leave the destination in either state. An
application that needs more has to arrange it at a level this adapter
does not reach. Windows offers no equivalent rename guarantee either:
its `rename()` can fail outright while another process holds the
destination open.

`copy()` publishes what the handle it opened on the source read. That
handle keeps reading the file it was opened on however the source
pathname changes afterward, so a replacement partway through cannot mix
two files' bytes into one destination. It is not a snapshot of the
file's contents: a writer modifying that same file in place while the
copy runs is read as it goes.

A copy that retains the source's visibility — the default — reads the
mode it will publish at off the source pathname *before* it opens that
handle. So it reads the pathname once more with the handle already
open, and fails the copy with `UnableToCopyFile`, nothing published,
when the two readings describe different files. That is what stops a
public file replaced by a private one in between from being published
at the public mode. Like the symlink checks below it is a check and not
a lock: a replacement reverted before the second reading, or landing
after that reading, reads as unchanged. One landing between the open
and that reading fails the copy, though the handle it holds would have
read the original file through to the end.

## The staging namespace is reserved

The directory step 2 creates is named `.kinetis-stage.` followed by 32
lowercase hexadecimal digits, and that name, matched whole, belongs to
the adapter:

- `listContents()` reports no entry carrying it, shallow or deep, and
  never descends into one, so the partially written file inside a
  publication still in flight is out of reach of a listing too. A
  listing running beside a publication, or after a cleanup that could
  not remove one, reports published objects and nothing else.
- A path whose segment carries it, at any depth, is refused with
  `Kinetis\Storage\Exception\ReservedPathDetected` before any
  filesystem call is made — every operation, on both operands of a
  `move()` or a `copy()`, through the one admission the confinement
  rules below live in.
- `deleteDirectory()` walks and removes them. `rmdir(2)` refuses a
  directory that still holds anything, so a skipped leftover would
  leave its parent undeletable.

Nothing else is hidden or refused: `.htaccess`, `.kinetis-stage`, and
the prefix carrying a shorter, longer, uppercase or non-hexadecimal
tail all list, read and write like any other path.

## Resource methods

`readStream()` reads the whole object through the driver and hands it
back as a `php://temp` resource, buffered in full before the caller
gets anything. `php://temp` keeps up to 2 MiB in memory and spills the
rest to a temporary file, so memory and disk cost the object's own size
and the spill blocks the thread for as long as that disk takes. A
failure there — a full or unwritable spill disk above all — is this
operation's own `UnableToReadFile`, including where an application
error handler converts the underlying warning into a throw of its own,
and the temporary resource is closed rather than left open. Prefer
`read()` unless a consumer requires a resource.

`writeStream()` transfers the caller's resource in bounded chunks and
never holds the whole input: it reads a chunk with PHP's own stream
functions on the calling thread, writes that chunk to the staged file
through the driver, and repeats. A read from a memory resource returns
immediately; one from a socket suspends the Fiber until it is readable;
one from a regular file, or from a temporary file that has spilled,
goes to disk and blocks the thread until the disk answers — a
resource's non-blocking mode governs sockets and pipes and does not
make disk I/O asynchronous. The resource is not closed, and the
non-blocking mode `Amp\ByteStream\ReadableResourceStream` sets on it to
install its readability watcher is restored, where the stream reports
one at all.

Neither method hands back something that streams lazily: `writeStream()`
consumes its input to the end before returning, and `readStream()`
buffers the object before returning one. There is no `Amp`-native
storage stream API here, and there will not be one until an in-repo
consumer streams a stored object straight to a response.

## Paths are confined to the root

Every path the local adapter is given becomes a location only after it
has been admitted, and both operands of a `move()` or a `copy()` are
admitted on their own terms. A refused path costs no filesystem call at
all — nothing is stat'ed, opened, created or removed:

- a `..` segment anywhere throws
  `League\Flysystem\PathTraversalDetected`. `../etc/passwd` and
  `uploads/../../etc/passwd` are both refused, and so is `a/../b`, which
  never leaves the root: the segment is refused rather than resolved, so
  no path is quietly rewritten into a different one on the way in.
- a control byte (NUL through `0x1F`, and `0x7F`) or a backslash throws
  `League\Flysystem\CorruptedPathDetected`. A NUL ends the string where
  C does, so a check and the kernel could otherwise read one path as two
  different files; a backslash is a separator to a caller and to
  Windows while being an ordinary filename byte to the segment split
  here.

`.` segments and repeated, leading or trailing separators name nothing
of their own and are dropped, so `a//b/` and `a/./b` both name `a/b`.
A path left with no segment at all names the root itself, and asking
about that is legitimate — `listContents('')`, `directoryExists('')` and
`fileExists('')` all answer for `FILESYSTEM_ROOT`, in every spelling
(`''`, `.`, `/`, `//`, `/./`).

```{code-block} php
use League\Flysystem\PathTraversalDetected;

try {
    $storage->read($pathFromTheRequest);
} catch (PathTraversalDetected $e) {
    // Refused. Nothing was read, and nothing was stat'ed.
}
```

Publishing *to* the root is not legitimate. `write()`, `writeStream()`
and the destination of a `move()` or a `copy()` refuse every spelling of
it — the root holds no file to publish over, and staging one there would
build the private staging directory in the root's own *parent*, outside
the tree. Each reports it as the failure its own interface declares
(`UnableToWriteFile`, `UnableToMoveFile`, `UnableToCopyFile`), decided
from the path alone: before the source is walked, before a parent
directory is created, and before a `writeStream()` resource is read
from.

```{code-block} php
use League\Flysystem\UnableToWriteFile;

try {
    // $key came from a request and arrived empty.
    $storage->write($key, $body);
} catch (UnableToWriteFile $e) {
    // "the destination names the storage root itself". Nothing was
    // written, and nothing was staged anywhere.
}
```

A segment naming one of the adapter's own staging directories is refused
by this same admission — see
[The staging namespace is reserved](#the-staging-namespace-is-reserved)
above.

`League\Flysystem\Filesystem` normalizes a path before any adapter sees
it, and that is not what this rests on: `Kinetis\Storage\AmpFileAdapter`
is a public class documented for direct use, so the check lives in the
operation rather than in front of it.

## Symlinks

A path that passes through an existing symlink below `FILESYSTEM_ROOT`
is refused with `League\Flysystem\SymbolicLinkEncountered`: every
component is checked, one at a time, from directly under the root down
to the target, with a check that inspects the component itself and never
follows it. Listing and recursive deletion apply the same check to every
entry they discover, which is also what stops a symlink cycle. The
configured root itself is not walked — it is operator configuration, and
a root that is a symlink is the operator's choice.

```{code-block} php
use League\Flysystem\SymbolicLinkEncountered;

try {
    $storage->read('uploads/avatar.png');
} catch (SymbolicLinkEncountered $e) {
    // $e->location() names the path that turned out to be a symlink.
}
```

`fileExists()`/`directoryExists()` answer `false` rather than throwing:
they already report "no" for anything else that isn't really there. A
path refused by the confinement rules above still throws there — a
traversal is a rejected request, not an answer of "no".

Which exception a symlink found *while listing* arrives as depends on
which object you are holding. `AmpFileAdapter::listContents()`, called
directly, throws `SymbolicLinkEncountered` naming the entry.
`FilesystemOperator::listContents()` wraps every failure its own
iteration sees, so the same walk arrives as `UnableToListContents` with
that `SymbolicLinkEncountered` as its `getPrevious()`.

**A link created while an operation runs is not detected.** The check
and the operation are separate syscalls, and nothing enforces that
nothing changes between them. `FILESYSTEM_ROOT` is a real boundary only
where this adapter is the sole writer to it. Where another writer shares
the tree, use an OS control instead: Linux's `nosymfollow` mount option
(5.10+), a dedicated bind-mount or mount namespace with no
symlink-creation rights for other writers, or a seccomp/LSM profile
restricting `symlink()`.

Under the worker-pool driver each component check is one round trip, so
`read('a/b/c.txt')` costs four rather than one.

## Recursive deletion

`deleteDirectory()` walks the whole subtree first and only then deletes
anything, so a symlink found anywhere in the tree throws before a single
entry has been removed. That covers the symlink policy specifically. A
failure partway through the deletion itself is not made atomic by it —
nothing short of a real filesystem transaction could undo what already
succeeded — so a caller catching a `FilesystemException` here, as
opposed to a `SymbolicLinkEncountered`, should expect the tree to be
partially deleted.

## What each operation throws

Confinement, the root-destination check, the symlink check and every
filesystem call an operation makes run inside one boundary, so a driver
failure at any stage arrives as the type `FilesystemOperator` declares
for that operation — including one raised while a listing is already
being iterated:

| Operation | Failure |
|---|---|
| `fileExists()` | `UnableToCheckFileExistence` |
| `directoryExists()` | `UnableToCheckDirectoryExistence` |
| `read()`, `readStream()` | `UnableToReadFile` |
| `write()`, `writeStream()` | `UnableToWriteFile` |
| `delete()` | `UnableToDeleteFile` |
| `deleteDirectory()` | `UnableToDeleteDirectory` |
| `createDirectory()` | `UnableToCreateDirectory` |
| `setVisibility()` | `UnableToSetVisibility` |
| `visibility()`, `mimeType()`, `lastModified()`, `fileSize()` | `UnableToRetrieveMetadata` |
| `listContents()` | `UnableToListContents` |
| `move()` | `UnableToMoveFile` |
| `copy()` | `UnableToCopyFile` |

A policy outcome is not a driver failure, and keeps its own type rather
than being relabeled as one of the above:

- `PathTraversalDetected` and `CorruptedPathDetected` — the path was
  refused before anything was touched.
- `SymbolicLinkEncountered` — a path component, or an entry found while
  walking, is a symlink.
- `Kinetis\Storage\Exception\ReservedPathDetected` — a segment of the
  path names a staging directory the adapter publishes through.
- `InvalidVisibilityProvided` — `visibility` or `directory_visibility`
  was not one of the two values the converter accepts.

All of them implement `League\Flysystem\FilesystemException`, so a
caller catching that alone still catches every failure this adapter
produces.

An `\Error` is not a driver failure either and is never relabeled: a
programmer error reaches the caller as itself, and so does anything a
`writeStream()` producer raises that is none of the types above.

## See also

- {doc}`storage-s3` — the S3-backed driver, plugged in via the same
  `FilesystemFactory`.
- {doc}`config` — the named-connection convention `FilesystemFactory`
  builds on.
- {doc}`routing-validation` — `UploadedFileInterface` parameter binding.
