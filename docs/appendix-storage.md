# Appendix: Storage Reference

The contracts behind {doc}`storage` and {doc}`storage-s3`: how the local
driver runs, how a write is published, the path and symlink rules, the
exception each operation reports, and S3's failure and credential
semantics. For the task-first path — configuration, injection, uploads
and the rules application code acts on — see {doc}`storage`.

## The local driver

`FilesystemFactory` builds each local filesystem on
`Amp\File\createDefaultDriver()`. With `ext-uv` or `ext-eio` loaded that
is an OS-native driver. Without either — the default for a stock PHP
image — it is a pool of up to eight worker *processes*, and every
filesystem call is an IPC round trip to one of them. Both extensions are
listed in the package's `suggest`.

Each `fromConfig()` call builds a driver of its own, and each of those
drivers a pool of its own: one pool per filesystem instance, not one per
event loop. A FrankenPHP image with eight threads therefore holds up to
sixty-four worker processes for one filesystem, and every named
connection built alongside it is another pool again. The container
binding builds one instance per process or thread and holds it; a named
connection is worth registering the same way.

`Amp\File\filesystem()`, the library's own shared instance, is not used:
it wraps the driver in a status cache that holds a positive `stat` per
path for 1,000 seconds and invalidates only on mutations made through
that same instance. Under a persistent worker that reports a file
another thread, another process or an external writer has already
deleted or rewritten. `AmpFileAdapter` accepts any
`Amp\File\Filesystem`, so a consumer who wants the cache can construct
one with it.

`FilesystemFactory::fromConfig()`, called directly, falls back to
`local` when the driver key is absent. The container binding does not:
it registers nothing until `FILESYSTEM_DRIVER` is set.

## Visibility and directory modes

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

## How a write is published

`write()`, `writeStream()` and `copy()` never build new content at the
destination path. Each one:

1. creates the destination's parent directory if it is missing;
2. creates a directory beside the destination with a random name and
   mode `0700`;
3. creates the new file inside it with an exclusive open;
4. writes the whole body and closes the file;
5. reads the closed file's length back and checks it against the number
   of bytes written to it;
6. applies the mode the file will carry once published — an explicit
   visibility first; for a `copy()` retaining visibility, the source's;
   otherwise the mode of the file being replaced, if there is one;
7. renames it over the destination;
8. removes the staging directory.

The rename in step 7 is the commit point, and it is atomic because the
staging directory is a child of the destination's own parent: both paths
are on one filesystem. A concurrent reader opening the destination sees
either the whole old file or the whole new one. Nothing before step 7
touches the destination, so a call that fails before it leaves the
destination exactly as it was.

A failure reported *by* step 7 carries the same
`UnableToWriteFile`/`UnableToCopyFile` type as one from any earlier
step, and not the same outcome. The adapter reports what the rename
call answered, and a lost answer — a worker-pool acknowledgement that
never arrives — is not a rename that did not happen, so the destination
may hold either file. Only a failure raised before step 7 is definite,
and there the destination is untouched.

Retrying is therefore the caller's decision. Writing and copying
replace the destination outright, so a retry is safe wherever this
caller is the only writer to that path. Where writers compete for one,
serializing ownership of it is an arrangement above this adapter.

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
mode it will publish at off the source pathname before it opens that
handle. Like the symlink checks below, that is a reading and not a
lock: a writer replacing the source in between publishes the new file's
bytes at the replaced file's mode.

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
  `move()` or a `copy()`, through the same admission as the path rules
  below.
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
and the temporary resource is closed rather than left open.

`writeStream()` transfers the caller's resource in bounded chunks and
never holds the whole input: it reads a chunk with PHP's own stream
functions on the calling thread, writes that chunk to the staged file
through the driver, and repeats. A read from a memory resource returns
immediately; one from a socket suspends the Fiber until it is readable;
one from a regular file, or from a temporary file that has spilled,
goes to disk and blocks the thread until the disk answers — a
resource's non-blocking mode governs sockets and pipes and does not
make disk I/O asynchronous. The non-blocking mode
`Amp\ByteStream\ReadableResourceStream` sets on the resource to install
its readability watcher is restored, where the stream reports one at
all.

The resource is not closed. A caller that detaches one closes it
itself:

```{code-block} php
$resource = $upload->getStream()->detach();

try {
    $storage->writeStream($path, $resource);
} finally {
    fclose($resource);
}
```

Neither method hands back something that streams lazily: `writeStream()`
consumes its input to the end before returning, and `readStream()`
buffers the object before returning one. There is no `Amp`-native
storage stream API here, and there will not be one until an in-repo
consumer streams a stored object straight to a response.

## Path confinement

When `AmpFileAdapter` is used directly, every path it receives becomes
a location only after it has been admitted. Both operands of a `move()`
or a `copy()` are admitted on their own terms. A refused path costs no
filesystem call at all — nothing is stat'ed, opened, created or removed:

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

Publishing *to* the root is not legitimate. `write()`, `writeStream()`
and the destination of a `move()` or a `copy()` refuse every spelling of
it — the root holds no file to publish over, and staging one there would
build the private staging directory in the root's own *parent*, outside
the tree. Each reports it as the failure its own interface declares
(`UnableToWriteFile`, `UnableToMoveFile`, `UnableToCopyFile`) with the
reason "the destination names the storage root itself", decided from
the path alone: before the source is walked, before a parent directory
is created, and before a `writeStream()` resource is read from.

Through the injected `FilesystemOperator`, Flysystem's own path
normalizer runs first: it converts a backslash to `/`, resolves a `..`
that stays inside the root (`a/../b` reaches the adapter as `b`), throws
`PathTraversalDetected` for one that climbs above it, and throws
`CorruptedPathDetected` for any control or format character. The
adapter's admission does not rest on that normalizer:
`Kinetis\Storage\AmpFileAdapter` is a public class documented for direct
use, so the check lives in the operation rather than in front of it.

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

## S3 stream writes

On S3, `writeStream()` sends one `PutObject` whose `Content-Length` is
the resource's full size. The body is read from the resource's start,
whatever its current position, in bounded chunks as the request is
written, so the whole object is never held in memory. The resource is
left open. A resource that is not seekable is refused with
`UnableToWriteFile` before any request is sent.

## S3 failure reporting

S3 answers some failures with HTTP 200 and an error document: a copy that
broke partway through, and a batch delete where individual keys were
refused. Kinetis reads both, so `copy()` raises `UnableToCopyFile`, a
`move()` whose copy failed raises `UnableToMoveFile` without deleting the
source, and `deleteDirectory()` raises `UnableToDeleteDirectory` rather
than reporting a prefix that still holds objects as gone.

`fileExists()` reports absence from a `HeadObject`, which S3 answers with
`403 Forbidden` instead of `404 Not Found` for a key the caller cannot
see. The adapter reads that as "no such file", so an absence check is
trustworthy only where the credentials hold `s3:ListBucket` on the
bucket alongside `s3:GetObject`.

`FILESYSTEM_S3_TIMEOUT` bounds each S3 request on its own — connect,
idle and total transfer alike. It is not one deadline across a Flysystem
operation that issues several requests. A request is one wire attempt:
no retry, and no redirect followed, since following one would replay a
request signed for the original host somewhere else. A request that
times out or loses its response may still have been applied.

## S3 directory deletion

`deleteDirectory()` lists the prefix one page of at most 1,000 keys at a
time and deletes that page with one `DeleteObjects` request before
requesting the next page by its continuation token, so it holds no more
than 1,000 key identifiers however large the prefix is. Each listing and
each delete gets the full `FILESYSTEM_S3_TIMEOUT`; there is no deadline
across the whole operation.

The sweep follows the continuation tokens once and is not atomic. When a
listing or a delete fails, `deleteDirectory()` raises
`UnableToDeleteDirectory`. Batches confirmed before the failure stay
deleted, and the failed delete itself may have been applied, because its
response can be lost after S3 acted on it. The exception's `reason()`
says whether at least one batch was confirmed complete; in either case
the directory may be partially deleted. A key written under the prefix
while the sweep runs can survive it.

## S3 credential resolution

Credentials resolve through AsyncAws's standard providers, in its
standard order: environment variables (including the STS assume-role that
`AWS_ROLE_ARN` selects), web identity, the shared credentials and config
files, ECS or EKS pod identity, then IMDS. The first that answers with
unexpired credentials wins; an expired answer is passed over like an
absent one.

Every provider in that chain that calls AWS uses the same Revolt
transport as the client itself, so an assume-role or an IMDS lookup
suspends the calling Fiber like any other call. The shared credentials
file, the shared config file and any web-identity or pod-identity token
file are read with native blocking calls, on first resolution and again
on each refresh.

Resolved credentials are held for reuse while they remain unexpired.
Nothing else is: a round that resolved nothing usable leaves no record of
having failed, so the next call runs every provider again — an instance
role, a container credential endpoint or a token file can appear after
a worker has started.

## See also

- {doc}`storage` — the task-first guide.
- {doc}`storage-s3` — S3 configuration.
- {doc}`appendix-configuration` — every `FILESYSTEM_*` key.
- {doc}`appendix-packages` — `Kinetis\Storage` and `Kinetis\StorageS3`
  in the package map.
