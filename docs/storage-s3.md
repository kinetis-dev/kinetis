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

Three optional settings:

```{code-block} text
FILESYSTEM_S3_PREFIX=app-data
FILESYSTEM_S3_ENDPOINT=https://s3.example-compatible.com
FILESYSTEM_S3_PATH_STYLE=true
```

`FILESYSTEM_S3_PREFIX` puts everything under a key prefix within the
bucket, so a shared bucket can still keep an app's files together.
`FILESYSTEM_S3_ENDPOINT` points at an S3-compatible service instead of
real AWS S3 (MinIO, for example). `FILESYSTEM_S3_PATH_STYLE` (`false` by
default, matching real AWS S3) switches the URL style requests use — most
non-AWS S3-compatible services, MinIO included, need this set to `true`.

## Credentials

Credentials resolve through AsyncAws's standard chain, in its standard
order: environment variables (including the STS assume-role that
`AWS_ROLE_ARN` selects), web identity, the shared credentials and config
files, ECS or EKS pod identity, then IMDS. There is nothing to
configure.

Every provider in that chain that calls AWS uses the same Revolt
transport as the client itself, so an assume-role or an IMDS lookup
suspends the calling Fiber like any other call. The shared credentials
file, the shared config file and any web-identity or pod-identity token
file are read with native blocking calls, on first resolution and again
on each refresh. Resolved credentials are held until they expire.

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
