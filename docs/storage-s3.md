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
| `FILESYSTEM_S3_ACCESS_KEY_ID` | — | Static access key for this connection only. Set together with the secret. |
| `FILESYSTEM_S3_SECRET_ACCESS_KEY` | — | Secret for `FILESYSTEM_S3_ACCESS_KEY_ID`. |

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
FILESYSTEM_S3_ACCESS_KEY_ID=minio-user
FILESYSTEM_S3_SECRET_ACCESS_KEY=minio-password
```

A second connection, S3 or local, uses the scoped `FILESYSTEM_{NAME}_*`
keys described in {doc}`storage`.

## Credentials

`FILESYSTEM_S3_ACCESS_KEY_ID` and `FILESYSTEM_S3_SECRET_ACCESS_KEY` give
one connection its own static keys. The connection then signs with
exactly those keys and consults nothing else — no `AWS_*` variable, no
file and no role. Use them for an S3-compatible service such as MinIO,
so its keys never sit in `AWS_ACCESS_KEY_ID`, where SQS, SES and every
other AWS client in the process would sign with them. Set both or
neither; one alone fails at boot.

Without them, credentials come from AWS's standard chain:
`AWS_ACCESS_KEY_ID`/`AWS_SECRET_ACCESS_KEY` (or an `AWS_ROLE_ARN` role),
web identity, the shared credentials and config files, ECS or EKS pod
identity, then the instance role. On AWS in production, prefer the role
your platform attaches over static keys.
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

(storage-s3-direct-uploads)=
## Upload directly from the browser

An ordinary upload is a request body, which the worker holds in memory
up to `MAX_BODY_SIZE` (2 MiB by default, see {doc}`middleware`), and on
AWS Lambda never exceeds the 6 MB invocation payload (see
{doc}`runtime-adapters`). For larger files, the application signs a POST
policy for one object, the browser sends the file straight to the
bucket, and the application then confirms the object through the same
`FilesystemOperator` it uses for everything else.

`Kinetis\StorageS3\DirectUploads` signs the policy. It reads the same
`FILESYSTEM_S3_*` keys as the filesystem connection of the same name,
so it addresses the same bucket and prefix, and it holds no request
state. Build it once in `bootstrap.php`:

```{code-block} php
:caption: bootstrap.php

use Kinetis\Config\Config;
use Kinetis\Container\AppScope;
use Kinetis\StorageS3\DirectUploads;

return static function (AppScope $app, Config $config): void {
    $app->instance(DirectUploads::class, DirectUploads::fromConfig($config));
};
```

`DirectUploads::fromConfig($config, 'media')` reads a named connection's
`FILESYSTEM_MEDIA_S3_*` keys instead.

The application chooses the key. The route below issues an upload under
a generated name, bounded in size, with an exact type:

```{code-block} php
use Kinetis\Http\Attributes\Post;
use Kinetis\StorageS3\DirectUploads;

final readonly class VideoUploadController
{
    public function __construct(private DirectUploads $uploads) {}

    #[Post('/video-uploads')]
    public function authorize(): array
    {
        $path = 'staging/videos/' . bin2hex(random_bytes(16)) . '.mp4';
        $post = $this->uploads->authorize(
            $path,
            maxBytes: 500_000_000,
            expiresInSeconds: 900,
            contentType: 'video/mp4',
        );

        // Record $path against the caller before returning it; the
        // confirmation reads that record, never a path the browser sends.
        return [
            'url' => $post->url,
            'fields' => $post->fields,
            'expiresAt' => $post->expiresAt->format(DATE_ATOM),
        ];
    }
}
```

{ref}`appendix-storage-s3-post-policy` lists every condition the policy
carries, every field, and every argument `authorize()` refuses.

### Send the form

The browser posts `multipart/form-data` to `url`:

- Send every entry of `fields` unchanged, under its own name.
- Append the file last, as `file`, and add no other field. S3 refuses a
  field the policy does not cover.
- The fields other than the file are limited to 20 KB in total.
- Success is `204 No Content`. A refusal is an XML error document:
  `EntityTooLarge` above the maximum size, `EntityTooSmall` below the
  minimum, `AccessDenied` for a changed field or an expired policy.

```{code-block} javascript
const form = new FormData();
for (const [name, value] of Object.entries(upload.fields)) {
    form.append(name, value);
}
form.append('file', file);

const response = await fetch(upload.url, { method: 'POST', body: form });
```

The bucket must allow the cross-origin POST. Configuring its CORS rule
is the operator's job; this package never changes bucket settings. A
minimal rule:

```{code-block} json
[
    {
        "AllowedOrigins": ["https://app.example.com"],
        "AllowedMethods": ["POST"],
        "AllowedHeaders": ["*"]
    }
]
```

### Confirm the upload

Issue uploads under a staging prefix, such as `staging/`, and give the
bucket a lifecycle rule that expires objects under it after a day, so an
upload that is never confirmed is removed by S3. Include
`FILESYSTEM_S3_PREFIX` in the rule's prefix when one is set.

When the browser reports success, confirm the path recorded for that
caller, then move the object out of staging:

```{code-block} php
use Kinetis\Http\Responses\ErrorResponse;

// $path is the path this application issued and recorded for the caller.
if (!$storage->fileExists($path)) {
    return ErrorResponse::create(409, 'The upload has not arrived.');
}

$size = $storage->fileSize($path); // within the authorized range
$storage->move($path, 'videos/' . basename($path));
```

- `fileExists()` reads `403 Forbidden` as absence; see
  [Permissions and uncertain outcomes](#permissions-and-uncertain-outcomes).
- `mimeType()` returns the pinned `Content-Type` when `authorize()` was
  given one. Without one the policy covers no `Content-Type` field, the
  browser cannot send one, and the object carries S3's default,
  `binary/octet-stream`. Neither checks the content; inspect the object
  before trusting its format.
- Until the policy expires, the browser can post again to the same key
  and replace the object, so confirm, then move it.

### Local development

The worker and the browser both reach `FILESYSTEM_S3_ENDPOINT`: the
worker confirms uploads through it, and the browser posts to the `url`
built from it. A
Compose service name such as `minio` resolves only inside the Compose
network, so choose a host name that resolves to the storage service for
both, and publish its port.

LocalStack enforces the size range, the exact conditions and the expiry,
but checks neither the signature nor extra fields. A form that works
against it can still be refused by S3.

## See also

- {doc}`storage` — using the filesystem, uploads, named connections and
  common errors.
- {doc}`appendix-storage` — S3 failure, deletion and credential
  semantics, and the browser upload policy.
- {doc}`appendix-configuration` — every `FILESYSTEM_S3_*` key.
