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
setup: it binds `FilesystemOperator`, so a controller or command
constructor-injects it with nothing to register.

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
`FILESYSTEM_DRIVER` is absent. The container binding above does not:
with no `FILESYSTEM_DRIVER` set, the package registers nothing at all,
since binding a filesystem into every application that merely installed
the package would be guessing at intent. Set the key to get the
binding. `FILESYSTEM_ROOT` is required either way; there's no sane
default to guess, since a wrong one could write files somewhere
unintended.

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

`move()` applies an explicit `visibility` to whatever arrives at the
destination, through the converter its kind calls for: a directory lands
on the directory mode, never on a file's `0600`, which would leave its
own contents unreachable. The conversion happens before a parent is
created or anything is renamed, so an invalid value throws
`InvalidVisibilityProvided` with the tree exactly as it was.

## Writes are staged privately and published atomically

A file the local driver creates starts at whatever the runtime's umask
produces — world-readable on most deployments — and a file created in a
directory others can read can be opened the instant it exists, keeping
that descriptor through every later permission change. So `write()`,
`writeStream()`, and `copy()` never build new content at the destination
path. Each one:

1. creates a directory beside the destination with a random name and
   mode `0700`;
2. creates the new file inside it and sets it to `0600` while it is
   still empty;
3. writes the whole body and closes the file;
4. reads the closed file's length back and checks it against the number
   of bytes written to it;
5. applies the mode the file will carry once published;
6. renames it over the destination.

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

### The privacy boundary

`0700` on the staging directory excludes other Unix users, and that is
the boundary this staging provides. It is not a boundary against the
service itself: a process running under the same UID that can list the
destination's parent can enter the staging directory and open what is
in it. Isolating writers from each other needs separate UIDs, which is
an operational decision this adapter cannot make.

Within that boundary the ordering still matters. `openFile()` creates
the staged file at the umask mode, not at `0600`; the `0700` directory
is what covers the window between that creation and the `chmod` in step
2, and the file is empty for all of it. Step 5 is the first moment the
file carries a mode another user could act on, and by then it is
complete and verified.

### What the length check promises

`Amp\File\File::write()` returns nothing and is not required to have
stored what it accepted: the driver behind a local file calls `fwrite()`
once and only rejects an outright failure, so a short write against a
full disk or a quota returns as though the whole body landed. Step 4
rejects that, and a length the filesystem cannot report fails the call
too.

The check is on length, and length is the promise: a destination is
never published short. A storage layer that keeps the right number of
wrong bytes is a different failure, and catching it would mean reading
every staged file back to compare it, doubling the I/O of every write.

### The rename, and the three outcomes of one that fails

The rename in step 6 is the commit point. On POSIX it replaces the
destination atomically for concurrent observers: another reader opening
the path sees either the whole old file or the whole new one, never a
partial or mixed one. A call that stops before the rename leaves the old
destination in place, because nothing before that step touches it.

This holds because the staging directory is a child of the destination's
own parent, so both paths are on one filesystem — a rename across
filesystems is a copy-and-unlink and is not atomic. Windows offers no
equivalent guarantee: its `rename()` can fail outright while another
process holds the destination open.

The guarantee is about what concurrent readers observe, not about what
survives a crash. Nothing here issues `fsync(2)` on the file or on the
directory, so a process kill, a kernel panic or a power loss can leave
the destination in either state, or leave the rename unrecorded with the
data already written. An application that needs durability across those
has to arrange it at a level this adapter does not reach.

A rename that reports failure has not necessarily failed. `amphp/file`
runs `rename(2)` in a worker, and the reply can be lost after the kernel
has already committed. The adapter therefore records the staged file's
device and inode before the rename and reads them back afterward, which
gives three outcomes:

- **Not committed** — that same inode is still in the staging
  directory. The destination is untouched, the staged file is removed,
  and the call throws `UnableToWriteFile` or `UnableToCopyFile`.
- **Committed** — the staged inode is gone from the staging directory
  and is the one now at the destination. The write succeeded; the
  failed reply is not reported.
- **Indeterminate** — anything else: a status that cannot be read, a
  filesystem that supplies no inode, or a destination holding neither
  the old file nor the staged one. The call throws
  `Kinetis\Storage\Exception\IndeterminatePublicationException`, which
  implements `League\Flysystem\FilesystemException`. **Inspect the
  destination.** It is not reported as a failed write, because that
  would claim the old file survived when that has not been
  established. Nothing is deleted on this path: an object whose
  ownership is not established is not the adapter's to remove.

### What a failed call can leave behind

No failure publishes a destination object except the committed outcome
above. Cleanup of the staged file and its directory is *attempted*, and
never allowed to mask the failure being reported — which is also its
limit. What can remain:

- a `0600` staged file inside its `0700` directory, when that cleanup
  fails or when a handle that could not be closed cannot be unlinked
  portably;
- an empty `0700` directory, when the cleanup after a successful rename
  fails, or when creating the staging directory failed in a way that
  leaves the outcome unknown — a lost `mkdir(2)` reply does not prove
  nothing was created, and removing a directory on that guess could
  destroy a path the call never owned;
- directories created to hold the destination, which are never rolled
  back on any path: another call writing nearby may already be using
  them.

A call that requests no visibility invents none: replacing a file keeps
the mode that file already had, and a new file lands on the umask
default.

### The source of a copy

`copy()` reads its source's status before opening it and reads it again
after the byte copy, comparing file type, permission bits, device and
inode. A filesystem that reports no inode makes the source
unverifiable, and that fails the copy rather than falling back to mode
bits, which cannot tell one file from another at the same path. The
check runs for every copy, whatever the destination's mode comes from,
so a source replaced mid-copy by a file carrying the very same
permissions is rejected under an explicit `visibility` and under
`retain_visibility: false` just as it is under retention. A disagreement publishes nothing and throws
`UnableToCopyFile` with the `UnableToRetrieveMetadata` that detected it
as its cause.

That pairing narrows the same check-then-use gap the next section
describes and, for the same structural reason, does not close it: a
source mutated in place, or replaced and then put back, between the two
readings is the same file by every measure a `stat` can report, and
nothing binds either reading to the bytes the read handle streamed.
`copy()` is atomic in what it publishes, not in what it reads.

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

Publishing *to* the root is not. `write()`, `writeStream()` and the
destination of a `move()` or a `copy()` refuse every spelling of it —
the root holds no file to publish over, and staging one there would
build the private staging directory in the root's own *parent*, outside
the tree. Each reports it as the failure its own interface declares
(`UnableToWriteFile`, `UnableToMoveFile`, `UnableToCopyFile`), decided
from the path alone: before the source is walked, before a parent
directory is created, and before a `writeStream()` resource is read
from, so the call costs no filesystem access and leaves a caller's
stream at the position they handed it over at.

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

`League\Flysystem\Filesystem` normalizes a path before any adapter sees
it, and that is not what this rests on: `Kinetis\Storage\AmpFileAdapter`
is a public class documented for direct use, so the check lives in the
operation rather than in front of it. Behind a `FilesystemOperator` the
normalizer refuses an escaping traversal first, with the same exception
type, and normalizes away a relative segment that resolves back inside;
called directly, the adapter refuses both.

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
exception a caller checking mere existence wouldn't expect. A path
refused by the confinement rules above still throws there — a traversal
is a rejected request, not an answer of "no".

Which exception a symlink found *while listing* arrives as depends on
which object you are holding. `AmpFileAdapter::listContents()`, called
directly, throws `SymbolicLinkEncountered` naming the entry.
`FilesystemOperator::listContents()` wraps every failure its own
iteration sees, so the same walk arrives as `UnableToListContents` with
that `SymbolicLinkEncountered` as its `getPrevious()`. Both are the real
behavior of the object you called; neither is a layer the adapter adds.

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

## What each operation throws

Confinement, the root-destination check, the symlink check and every
filesystem call an operation makes run inside one boundary, so a driver
failure at any stage arrives as the type `FilesystemOperator` declares
for that operation — including one raised while a listing is already
being iterated, and one raised by the worker pool the local driver runs
its filesystem calls in:

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

Where those driver failures come from is worth being concrete about.
With neither `ext-uv` nor `ext-eio` loaded — the default for a stock PHP
image — `amphp/file` runs every filesystem call as a task in a pool of
worker processes, and translates a worker or task failure into its own
`Amp\File\FilesystemException` or `Amp\ByteStream\StreamException`. Two
paths it does not translate: acquiring a worker to open a file with, and
closing an open handle. So a pool that cannot start a worker process, or a worker that
dies mid-`fclose`, surfaces as `Amp\Parallel\Worker\WorkerException`,
`Amp\Parallel\Worker\TaskFailureException` or
`Amp\Parallel\Context\ContextException` — and `write()`,
`writeStream()`, `copy()` and `mimeType()`, the four operations that
open or close a handle, report each of them as their own row above,
with the original chained as `getPrevious()`. Nothing about the pool
reaches a caller as a type they have no reason to expect.

A policy outcome is not a driver failure, and keeps its own type rather
than being relabeled as one of the above:

- `PathTraversalDetected` and `CorruptedPathDetected` — the path was
  refused before anything was touched.
- `SymbolicLinkEncountered` — a path component, or an entry found while
  walking, is a symlink.
- `InvalidVisibilityProvided` — `visibility` or `directory_visibility`
  was not one of the two values the converter accepts.
- `IndeterminatePublicationException` — a rename failed without
  establishing what it did.

All of them implement `League\Flysystem\FilesystemException`, so a
caller catching that alone still catches every failure this adapter
produces.

A programmer error is not a driver failure either, and is never
relabeled: an `\Error` — including `Amp\Parallel\Worker\TaskFailureError`,
which carries an `\Error` raised inside a worker, and
`Amp\File\PendingOperationError` — reaches the caller as itself. So does
anything a `writeStream()` producer raises that is none of the types
above. Cleanup never displaces any of them: a handle that fails to close
while a failure is already being reported is absorbed, and the failure
that prompted the cleanup is the one raised.

## See also

- {doc}`storage-s3` — the S3-backed driver, plugged in via the same
  `FilesystemFactory`.
- {doc}`config` — the named-connection convention `FilesystemFactory`
  builds on.
- {doc}`persistence` — MySQL, Postgres, and Redis, which run the same
  way: without blocking the rest of your application.
- {doc}`routing-validation` — `UploadedFileInterface` parameter binding.
