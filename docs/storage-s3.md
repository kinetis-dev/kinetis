# Storage (S3)

````{note}
Not part of core. Install it separately:

```{code-block} sh
composer require kinetis/storage-s3
```
````

Adds Amazon S3 (and S3-compatible services) as a second storage option
for {doc}`storage`, alongside local disk — installing `kinetis/storage-s3`
brings `kinetis/storage` in with it as a real dependency, so
`Kinetis\Storage\FilesystemFactory` (below) is available with nothing
else to install.

```{code-block} php
use Kinetis\Storage\FilesystemFactory;

$storage = FilesystemFactory::fromConfig($config); // FILESYSTEM_DRIVER=s3

$storage->write('avatars/user-42.png', $imageContents);
$contents = $storage->read('avatars/user-42.png');
$storage->delete('avatars/user-42.png');
```

Application code that already uses `$storage` needs no changes at all to
switch from local disk to S3 — only your configuration changes. Every S3
call travels on the Revolt-native transport from
{doc}`revolt-http-client`, so it suspends the calling Fiber rather than
blocking the worker.

## Configuring

```{code-block} text
FILESYSTEM_DRIVER=s3
FILESYSTEM_S3_BUCKET=my-app-bucket
FILESYSTEM_S3_REGION=us-east-1
```

`FILESYSTEM_S3_BUCKET` and `FILESYSTEM_S3_REGION` are required — there's
no sane default to guess for either. Credentials need nothing
Kinetis-specific set up at all — see Credentials below.

Four optional settings:

```{code-block} text
FILESYSTEM_S3_PREFIX=app-data
FILESYSTEM_S3_ENDPOINT=https://s3.example-compatible.com
FILESYSTEM_S3_PLAINTEXT=false
FILESYSTEM_S3_TIMEOUT=60
```

`FILESYSTEM_S3_PREFIX` puts everything under a key prefix within the
bucket, so a shared bucket can still keep an app's files together.

`FILESYSTEM_S3_ENDPOINT` points at an S3-compatible service instead of
AWS S3 (MinIO, for example). It is one origin — a scheme, a host and an
optional port, with no userinfo, path, query or fragment — and anything
else is refused when the filesystem is built. An explicit endpoint is
addressed path-style (`https://endpoint/bucket/key`), because a service
on a fixed hostname cannot offer the bucket as a DNS label the way AWS's
own virtual-hosted style needs. Leave the key unset and the destination
is AsyncAws's regional endpoint table; an `AWS_ENDPOINT_URL` sitting in
the environment for some other tool is refused rather than quietly
redirecting this application's objects, so name the endpoint here when
you want one.

`FILESYSTEM_S3_PLAINTEXT=true` is what allows an `http://` endpoint.
`http://minio:9000` between containers on one Compose network is
ordinary; a public plain-HTTP endpoint carrying credentials and object
data is not, and nothing in the hostname tells those apart, so the
decision is yours to record.

`FILESYSTEM_S3_TIMEOUT` (seconds, default `60`) bounds each S3 request on
its own — connect, idle and total transfer alike. It is not one deadline
across a Flysystem operation that issues several requests, such as
`deleteDirectory()` — see Deleting a directory below. A request is one
wire attempt — no retry, and no redirect followed.

## Visibility

Objects are private. Writes and copies carry no ACL at all, not even the
`private` one Flysystem's S3 adapter defaults to, so a bucket with Object
Ownership set to bucket owner enforced — where any request carrying an
ACL is rejected outright — works unchanged. A `['visibility' => 'public']`
write is refused before it leaves the process, and `copy()` and `move()`
read no ACL from the source object.

`setVisibility()` reaches S3's `PutObjectAcl` as the vendor adapter
writes it, so a bucket with ACLs disabled rejects it. Grant public read
through a bucket policy instead.

## Failure reporting

S3 answers some failures with HTTP 200 and an error document: a copy that
broke partway through, and a batch delete where individual keys were
refused. Kinetis reads both, so `copy()` raises `UnableToCopyFile`, a
`move()` whose copy failed raises `UnableToMoveFile` without deleting the
source, and `deleteDirectory()` raises `UnableToDeleteDirectory` rather
than reporting a prefix that still holds objects as gone.

`fileExists()` reports absence from a `HeadObject`, which S3 answers with
`403 Forbidden` instead of `404 Not Found` for a key the caller cannot
see. The adapter reads that as "no such file", so grant `s3:ListBucket`
on the bucket alongside `s3:GetObject` wherever an absence check has to
be trustworthy.

## Deleting a directory

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

## Credentials

Credentials resolve through AsyncAws's standard providers, in its
standard order: environment variables (including the STS assume-role that
`AWS_ROLE_ARN` selects), web identity, the shared credentials and config
files, ECS or EKS pod identity, then IMDS. The first that answers with
unexpired credentials wins; an expired answer is passed over like an
absent one. There is nothing to configure.

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

## Named connections

```{code-block} php
$backups = FilesystemFactory::fromConfig($config, 'backups');
```

```{code-block} text
FILESYSTEM_BACKUPS_DRIVER=s3
FILESYSTEM_BACKUPS_S3_BUCKET=my-app-backups
FILESYSTEM_BACKUPS_S3_REGION=eu-west-1
```

Same convention as everywhere else in Kinetis (see {doc}`config`):
`'default'` reads the plain `FILESYSTEM_S3_*` keys above, and any other
name reads `FILESYSTEM_{NAME}_S3_*` instead. A local connection and an S3
connection can happily coexist side by side, each chosen by its own
`FILESYSTEM_{NAME}_DRIVER`.

## If the package isn't installed

Setting `FILESYSTEM_DRIVER=s3` without having run
`composer require kinetis/storage-s3` produces a clear error telling you
which package to install, rather than a confusing crash.

## See also

- {doc}`storage` — the local driver, and everything about reading,
  writing, and listing files that works the same way regardless of
  backend.
- {doc}`config` — the named-connection convention used above.
