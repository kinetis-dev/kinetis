# Storage (S3)

````{note}
Not part of core. Install it separately:

```{code-block} sh
composer require kinetis/storage-s3
```
````

Adds Amazon S3 and S3-compatible services as a backend for
{doc}`storage`. The package brings `kinetis/storage` with it, and
application code keeps injecting the same `FilesystemOperator`; only
configuration changes. Every S3 call travels on the Revolt-native
transport from {doc}`revolt-http-client`, so it suspends the calling
Fiber instead of blocking the worker.

## Configure

```{code-block} text
:caption: .env
FILESYSTEM_DRIVER=s3
FILESYSTEM_S3_BUCKET=my-app-bucket
FILESYSTEM_S3_REGION=us-east-1
```

`FILESYSTEM_S3_BUCKET` and `FILESYSTEM_S3_REGION` are required. The
optional keys:

| Key | Default | Purpose |
|---|---|---|
| `FILESYSTEM_S3_PREFIX` | — | Key prefix for every object, so a shared bucket keeps one application's files together. |
| `FILESYSTEM_S3_ENDPOINT` | — | An S3-compatible service such as MinIO. One origin — scheme, host and optional port — addressed path-style. |
| `FILESYSTEM_S3_PLAINTEXT` | `false` | Allows an `http://` endpoint. |
| `FILESYSTEM_S3_TIMEOUT` | `60` | Seconds for each S3 request. |

With `FILESYSTEM_S3_ENDPOINT` unset, requests go to AWS's regional
endpoint, and an `AWS_ENDPOINT_URL` set in the environment for another
tool is refused rather than followed. Name a non-AWS endpoint here.

Plain HTTP carries credentials and object data unencrypted, so enable
`FILESYSTEM_S3_PLAINTEXT` only on a private network, such as MinIO
beside the application on one Compose network:

```{code-block} text
:caption: .env
FILESYSTEM_DRIVER=s3
FILESYSTEM_S3_BUCKET=app
FILESYSTEM_S3_REGION=us-east-1
FILESYSTEM_S3_ENDPOINT=http://minio:9000
FILESYSTEM_S3_PLAINTEXT=true
AWS_ACCESS_KEY_ID=minio-user
AWS_SECRET_ACCESS_KEY=minio-password
```

A second connection, S3 or local, uses the scoped `FILESYSTEM_{NAME}_*`
keys described in {doc}`storage`.

## Credentials

Kinetis reads no credential keys of its own. Credentials come from AWS's
standard chain: `AWS_ACCESS_KEY_ID`/`AWS_SECRET_ACCESS_KEY` (or an
`AWS_ROLE_ARN` role), web identity, the shared credentials and config
files, ECS or EKS pod identity, then the instance role. In production,
prefer the role your platform attaches over static keys.
{doc}`appendix-storage` states the resolution order, what is cached, and
which lookups block.

## Visibility

Objects are private. Writes and copies send no ACL at all, so a bucket
with Object Ownership set to bucket owner enforced works unchanged. A
write asking for `public` visibility is refused before any request is
sent. Grant public read through a bucket policy instead;
`setVisibility()` sends an object ACL, which a bucket with ACLs disabled
rejects.

## Permissions and uncertain outcomes

- `fileExists()` reads `403 Forbidden` as absence, because S3 answers
  `403` rather than `404` for a key the caller cannot see. Grant
  `s3:ListBucket` on the bucket alongside `s3:GetObject` wherever an
  absence check must be trustworthy.
- Each request is one attempt within `FILESYSTEM_S3_TIMEOUT`, with no
  retry. A request that times out or loses its response may still have
  been applied, so retry a write or delete only where that is safe for
  your data.
- `deleteDirectory()` deletes a prefix page by page and is not atomic. A
  failure can leave some objects deleted, and an object written under the
  prefix while it runs can survive.

{doc}`appendix-storage` states the failure reporting and directory
deletion contracts in full.

## See also

- {doc}`storage` — using the filesystem, uploads, named connections and
  common errors.
- {doc}`appendix-storage` — S3 failure, deletion and credential
  semantics.
- {doc}`appendix-configuration` — every `FILESYSTEM_S3_*` key.
