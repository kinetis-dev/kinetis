# Runtime Adapters

One `public/index.php` serves a Kinetis application under FrankenPHP,
PHP-FPM, RoadRunner and AWS Lambda:

```{code-block} php
:caption: public/index.php

use Kinetis\Runtime\HttpStartup;

require dirname(__DIR__) . '/vendor/autoload.php';

HttpStartup::run(__DIR__);
```

`HttpStartup` detects the runtime once per boot and drives it through
its adapter. Application code does not change between runtimes; the
deployment chooses the runtime and sets the few server settings this page
lists.

## Choose a runtime

| Runtime | Process model | Package | Choose it for |
|---|---|---|---|
| FrankenPHP worker mode | Each worker thread boots once and serves request after request | core | Production on a container or VM — Kinetis's primary target |
| PHP-FPM | Every request runs `public/index.php` from the start | core | Development with edit-and-reload, or an existing FPM platform |
| RoadRunner | Persistent PHP worker processes behind RoadRunner's Go server | `kinetis/roadrunner-adapter` | A persistent worker on a RoadRunner platform |
| AWS Lambda | A warm execution environment serves one invocation at a time | `kinetis/bref-adapter` | An API Gateway HTTP API or a Lambda Function URL |

Detection checks `frankenphp_handle_request()`, then `RR_MODE=http`, then
`AWS_LAMBDA_RUNTIME_API`, and falls back to PHP-FPM. A RoadRunner or
Lambda signal without its package installed fails at startup with a
message naming the package to install.

The choice changes four things an application acts on:

- **Code changes.** A persistent worker (FrankenPHP, RoadRunner) keeps
  loaded classes in memory, so an edited controller takes effect after a
  restart. PHP-FPM under `APP_ENV=development` picks it up on the next
  request.
- **Request isolation.** Under a persistent worker, application-scoped
  services outlive the request. Request data belongs in the request
  scope, never in a static or a shared service — see {doc}`container`.
- **Database I/O.** `DB_DRIVER=auto` selects the native non-blocking
  drivers under FrankenPHP and RoadRunner, and one blocking PDO
  connection under PHP-FPM and Lambda. {ref}`concurrency-overlap` shows
  what that means for `concurrently()`.
- **Streaming.** FrankenPHP and PHP-FPM stream a `StreamedResponse`.
  RoadRunner answers one with `501`, and Lambda fails the invocation.

Every runtime also needs the settings under "Request bodies: one contract
under every runtime" and, behind a proxy, `TRUSTED_PROXIES`.

## Running under FrankenPHP

Worker mode needs one PHP setting and the worker script:

```{code-block} ini
:caption: docker/kinetis.ini

enable_post_data_reading=0
```

```{code-block} bash
docker run --rm -p 8080:8080 -v "$PWD":/app -w /app \
    -v "$PWD/docker/kinetis.ini":/usr/local/etc/php/conf.d/zz-kinetis.ini:ro \
    dunglas/frankenphp:latest \
    frankenphp php-server --listen :8080 --root /app/public --worker /app/public/index.php
```

`enable_post_data_reading=0` is required: without it PHP consumes and
truncates the body before Kinetis runs, and the adapter refuses to serve.
`kinetis/pingpong`'s image uses this command and ships the same file.

```{warning}
FrankenPHP serves a request that matches no static file by running the
front controller classically unless a worker handles that same script.
`--worker` (or a Caddyfile `worker` directive) must name the same
`public/index.php` as the front controller. A different script leaves
every request on the classic path, with no error to say so.
```

A worker keeps `public/index.php`, including route discovery, loaded
across requests, so a code change needs a container restart. Use PHP-FPM
while editing code constantly.

### Sizing FrankenPHP's worker threads

Each worker thread processes one HTTP request at a time, start to
finish. `concurrently()` overlaps work *inside* one request; a thread
suspended on a database response is still not free to take a second
request. Cross-request concurrency is the thread count. Set it with a
Caddyfile `worker` block:

```{code-block}
:caption: Caddyfile

worker {
    file public/index.php
    num 20
}
```

or the shorthand `worker public/index.php 20`. Left unset, FrankenPHP
starts roughly 2× the CPU cores.

- **Requests dominated by waiting** — slow queries, remote APIs — want
  `num` well above the core count, closer to the expected number of
  concurrent requests. Undersizing produces queueing that looks like a
  slow application.
- **Requests mixing CPU with fast queries** — the common case with the
  native drivers — want `num` around 2–3× the core count. On an 8-vCPU
  host against a sub-millisecond database, 20 threads outperform 8 on
  every database-touching route (a 20-query fan-out by ~10%, single-query
  routes by ~9%) with no loss on CPU-pure routes.

Every thread runs `bootstrap.php` and the package bootstraps, so each
builds its own database pool. Keep threads × `DB_MAX_CONNECTIONS` under
the database's connection limit — see {doc}`persistence`'s "Sizing
`maxConnections` under worker mode" and {doc}`performance-tuning`.
Measure under realistic load: the two regimes want opposite corrections.

### Install `ext-event`

Without a loop extension, Revolt uses a `select()`-based driver that
cannot watch a file descriptor numbered above 1024. The native Postgres
driver, the Redis client and the HTTP client register socket watchers,
and under FrankenPHP the Go server's client sockets share the process's
descriptor table, so descriptor numbers pass 1024 under load. Install
`ext-event` (`pecl install event`) in any image that uses those clients;
Revolt selects it automatically. This is a correctness requirement, not
tuning. `ext-ev` and `ext-uv` also work; `ext-uv`'s only release is a
beta that must be pinned (`pecl install uv-0.3.0`).

The native MySQL driver registers no socket watcher, but mysqli's own
poll has a separate descriptor ceiling that no loop extension lifts — see
{doc}`performance-tuning`'s "mysqli's poll limit".

## Running under PHP-FPM

PHP-FPM needs the same PHP setting, and the web server in front of it
needs a body limit at least as large as `MAX_BODY_SIZE`:

```{code-block} dockerfile
:caption: docker/Dockerfile

FROM php:8.4-fpm-alpine
WORKDIR /app
COPY docker/kinetis.ini /usr/local/etc/php/conf.d/zz-kinetis.ini
CMD ["php-fpm", "-F"]
```

```{code-block} nginx
:caption: docker/nginx.conf

server {
    listen 8080;

    root /app/public;
    index index.php;

    # nginx reads the body before PHP does, so this must be at least
    # Kinetis's MAX_BODY_SIZE; raise both together.
    client_max_body_size 2m;

    location / {
        try_files $uri /index.php$is_args$args;
    }

    location ~ \.php$ {
        fastcgi_pass app:9000;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME /app/public/index.php;
        include fastcgi_params;
    }
}
```

`docker/kinetis.ini` carries the `enable_post_data_reading=0` shown
under FrankenPHP. `kinetis/skeleton` ships this setup with a
`docker-compose.yml` that mounts the project at `/app` in both
containers and names the PHP-FPM service `app`. Its copy of the ini also
turns PHP's error display off, so a diagnostic reaches the container log
rather than the response body.

nginx answers a body over `client_max_body_size` with its own `413`
before PHP runs. Its default is 1 MiB, under the 2 MiB `MAX_BODY_SIZE`
default, which is why the configuration sets `2m`.

nginx buffers a FastCGI response and delivers it once the script ends,
which turns a streamed response — an MCP progress stream, any
`StreamedResponse` — into one delayed lump. Add `fastcgi_buffering off;`
to the PHP location, or send `X-Accel-Buffering: no` on the streamed
response.

Every request reruns `public/index.php`, so production PHP-FPM depends on
the prebuilt artifact {doc}`caching` describes.

## Running under RoadRunner

````{note}
Not part of core. Install it separately:

```{code-block} sh
composer require kinetis/roadrunner-adapter
```
````

The package brings `spiral/roadrunner-worker` and `spiral/roadrunner-http`.
`rr serve` sets `RR_MODE=http` for the worker it spawns, which selects
the adapter. Two settings in `.rr.yaml` are required:

```{code-block} yaml
:caption: .rr.yaml

version: "3"

server:
  command: "php public/index.php"

http:
  address: 0.0.0.0:8080
  raw_body: true
  max_request_size: 10
```

```{code-block} bash
rr serve -c .rr.yaml
```

- **`http.raw_body: true`** hands PHP the bytes the client sent. Without
  it RoadRunner parses form bodies in Go and answers a body it cannot
  parse itself. The adapter checks this on every request and refuses to
  serve with an error naming the setting.
- **`http.max_request_size`**, in megabytes, bounds the body before PHP.

### `http.max_request_size` is the real defense against an oversized body

```{warning}
RoadRunner reads the whole body into memory before any PHP runs, and its
default `max_request_size` is 1000 MB. `MAX_BODY_SIZE` refuses a body
Kinetis already holds; it cannot bound RoadRunner's read, and a chunked
body with no declared length has no other bound. Set `max_request_size`
explicitly to what the application accepts, and set `MAX_BODY_SIZE` to
match (`MAX_BODY_SIZE=10485760` alongside `max_request_size: 10`), or a
body between the two limits reaches PHP and is refused there.
```

### `ext-sockets` under an Alpine-based image

`spiral/roadrunner-worker` requires `ext-sockets`. On an Alpine image,
`docker-php-ext-install sockets` also needs `linux-headers`:

```{code-block} dockerfile
FROM php:8.4-cli-alpine
RUN apk add --no-cache $PHPIZE_DEPS linux-headers \
 && docker-php-ext-install sockets
```

### Sizing RoadRunner's worker processes

`http.pool.num_workers` sets the number of PHP worker processes, each
serving one request at a time. Size it the way
[Sizing FrankenPHP's worker threads](#sizing-frankenphps-worker-threads)
describes: each process builds its own database pool, and the thread
ratios measured for FrankenPHP have not been re-measured for processes.

### A crash in one request doesn't take the worker down

An exception a handler throws is reported to RoadRunner with
`Worker::error()`, which answers that one client with an error, and the
worker keeps serving. A short `pool.supervisor.exec_ttl` still bounds a
worker's total lifetime; RoadRunner's default is `0s`, unlimited.

### What isn't supported

- **Response streaming.** A `StreamableResponseInterface` is answered
  with `501`, never buffered or dropped silently.
- **A purely-numeric header name** such as `123`. RoadRunner's PHP
  library drops it before the adapter sees the request.
- **Cookie order.** Names and values arrive intact; their order may not.

{ref}`runtime-reference-forwarded-headers` and the RoadRunner section of
{doc}`appendix-runtime` give the detection and delivery details.

## Running on AWS Lambda

````{note}
Not part of core. Install it separately:

```{code-block} sh
composer require kinetis/bref-adapter
```
````

`BrefLambdaAdapter` polls the Lambda Runtime API itself, with no
`bref/bref` handler in between: the one PHP process a Lambda container
runs must execute `public/index.php` directly, and it is that process
for which Lambda sets `AWS_LAMBDA_RUNTIME_API`, which the adapter reads
to select itself.

A `bref/php-84` image does not do that on its own. Its `CMD` is a
handler string, not a command — the image's entrypoint reads `CMD` into
`_HANDLER` and then runs `/var/runtime/bootstrap`, which starts Bref's
own runtime and never reaches Kinetis's front controller.

### Replacing the runtime bootstrap

Replace `/var/runtime/bootstrap` so it runs `public/index.php` instead:

```{code-block} dockerfile
:caption: docker/lambda/Dockerfile

FROM bref/php-84:3
COPY . /var/task
COPY docker/lambda/bootstrap /var/runtime/bootstrap
RUN chmod 0755 /var/runtime/bootstrap
CMD ["public/index.php"]
```

```{code-block} sh
:caption: docker/lambda/bootstrap

#!/bin/sh
exec /opt/bin/php /var/task/public/index.php
```

`CMD` still satisfies the entrypoint's one required argument, but
Kinetis reads no `_HANDLER` and ignores it. `exec` replaces the shell
with PHP, so PHP itself becomes the container's runtime process. The
entrypoint itself is unchanged, and the Runtime Interface Emulator it
embeds for local invocations runs this same replacement bootstrap. Pin
`bref/php-84` by tag or digest: this recipe relies on
`/var/runtime/bootstrap` and `/opt/bin/php` existing at those exact
paths.

The image must already contain `vendor/` and, in production,
`.kinetis-cache/compiled.php` — see {doc}`caching`. Lambda's `/var/task`
is read-only. `COPY` copies only what `.dockerignore` permits, and the
skeleton's own `.dockerignore` excludes both directories.

- **Invoke it through an HTTP API or a Function URL.** The adapter reads
  payload format 2.0 only. ALB events, REST API (payload format 1.0)
  events, and any event that fails validation are reported to Lambda as
  invocation errors and never reach a route.
- **Databases use PDO.** `DB_DRIVER=auto` selects a blocking PDO
  connection. {doc}`persistence`'s "Driver selection" covers when to
  select `DB_DRIVER=native` on Lambda.
- **The body limit is the platform's first.** API Gateway accepts and
  holds the whole body, up to Lambda's 6 MB invocation payload, before the
  adapter runs. `MAX_BODY_SIZE` refuses a larger body after delivery.
- **The scheme is always `https`.** An HTTP API and a Function URL are
  TLS-only; an event whose `x-forwarded-proto` claims `http` is refused.
  `TRUSTED_PROXIES` does not apply.
- **Binary responses and cookies need no code.** A response body that is
  not valid UTF-8 is base64-encoded for API Gateway, and every
  `Set-Cookie` becomes its own cookie entry.

What Lambda does not support:

- **Response streaming.** A `StreamableResponseInterface` fails the
  invocation rather than being buffered. Lambda response streaming is a
  different invocation model this adapter does not implement.
- **An unreachable Runtime API.** A failed poll or response post throws,
  and the function reports an error in CloudWatch.

{ref}`runtime-reference-lambda` lists every validated event field, how
the request's host, port and target are derived, and how each field maps
to PSR-7.

## Request bodies: one contract under every runtime

An adapter hands the body on as raw bytes. `RequestBodyMiddleware`, which
runs on every request, stages it, applies `MAX_BODY_SIZE` and the form
limits below, and parses a form, so the same body is accepted or refused
the same way under every runtime.

```{important}
**`enable_post_data_reading=0`** — FrankenPHP and PHP-FPM. PHP otherwise
reads and parses the body before Kinetis exists, silently truncating a
form at `max_input_vars` or emptying it past `post_max_size`.
`SuperglobalsBridge` refuses to serve without it. The setting is
`PHP_INI_PERDIR`: set it in `php.ini`, a `conf.d` file, `.htaccess` or the
FPM pool configuration, never in application code.

**`arg_separator.input=&`** — every runtime. `&` is PHP's default and the
only value the form parser accepts; any other value fails the first form
parse with a server error naming the setting.
```

### Set the edge limit with `MAX_BODY_SIZE`

`MAX_BODY_SIZE` (default 2 MiB) is the byte ceiling Kinetis enforces. The
server in front of PHP may read and bound the body first:

| Runtime | Limit before PHP | Action |
|---|---|---|
| FrankenPHP | none set by Kinetis | `MAX_BODY_SIZE` stops the read once the body passes it |
| PHP-FPM behind nginx | `client_max_body_size`, default 1 MiB | Set it to at least `MAX_BODY_SIZE` |
| RoadRunner | `http.max_request_size`, default 1000 MB | Set it explicitly and match `MAX_BODY_SIZE` |
| AWS Lambda | 6 MB invocation payload | Fixed by the platform |

Every request in progress holds its body in memory — see
{doc}`middleware`'s "Request body limits" for choosing `MAX_BODY_SIZE`
with `memory_limit`.

### Form limits

| Limit | Default | What it counts |
|---|---|---|
| `MAX_INPUT_VARS` | 512 | pairs in a raw url-encoded body, and leaf values in the parsed form |
| `MAX_FILE_PARTS` | 16 | leaf entries in `getUploadedFiles()` |
| `MAX_NESTING_DEPTH` | 8 | array levels a name builds — `a[b][c]=1` is 3 |
| `MAX_MULTIPART_PARTS` | 512 | parts in a raw multipart envelope, unnamed ones included |
| `MAX_PART_HEADERS` | 16 | header *lines* on any one multipart part, repeats included |
| `MAX_PART_HEADER_BYTES` | 8 KiB | bytes on one multipart header line |
| `MAX_BODY_SIZE` | 2 MiB | bytes in the request body, whatever its content type |

`MAX_BODY_SIZE` is configuration. The other six are
`Kinetis\Http\Form\FormLimits` constants, counted from the raw body
before anything parses it.

- A body past any limit is refused whole with `413`, naming the limit. A
  form is never handed on with the fields past the limit missing.
- A runtime whose `max_input_vars` or `max_input_nesting_level` is set
  below these limits is `413` naming that setting, rather than a form PHP
  would have shortened.
- A body that cannot be parsed is `400` with the fixed message
  `The request body could not be parsed.` Multipart bodies follow the
  byte-literal RFC 7578 subset browsers send; transfer encodings, encoded
  words, extended parameters and nested multipart parts are refused.

Both refusals happen before the handler runs.
{ref}`runtime-reference-body-staging` and
{ref}`runtime-reference-multipart` give the staging mechanism, the exact
multipart grammar and the logged failure categories.

## Forwarded headers: trust only your edge

A client can send `X-Forwarded-Proto` itself. By default Kinetis reads it
from no one, and a request's scheme is the one its listener serves.
Behind a proxy or load balancer that terminates TLS, name that edge so
absolute URLs, `Secure` cookies and OAuth redirects use `https`:

```{code-block} bash
:caption: .env

TRUSTED_PROXIES=10.0.0.0/8,172.16.0.0/12
```

- `TRUSTED_PROXIES` is a comma-separated list of addresses and CIDR
  ranges. An entry that is neither is refused at startup.
- Only a request whose connecting peer matches the list may set the
  scheme, under FrankenPHP, PHP-FPM and RoadRunner. From any other peer
  the header is ignored.
- A trusted proxy sending anything other than one `http` or `https` is
  answered with `400` before the handler.
- The request's client address stays the peer that connected.
  `RateLimitMiddleware` reads `X-Forwarded-For` under its own policy —
  see {doc}`middleware`'s "Behind a reverse proxy or load balancer".
- Lambda takes no policy: its scheme is always `https`.

{ref}`runtime-reference-forwarded-headers` describes how each adapter
applies the policy.

## Choose an adapter explicitly

A deployment that wants one adapter rather than detection passes a
factory to `HttpStartup::assemble()` and serves what it returns. The
factory receives the `TrustedProxies` policy the container settled on:

```{code-block} php
:caption: public/index.php

use Kinetis\Http\TrustedProxies;
use Kinetis\Runtime\Adapters\FpmAdapter;
use Kinetis\Runtime\HttpStartup;

require dirname(__DIR__) . '/vendor/autoload.php';

HttpStartup::assemble(
    dirname(__DIR__),
    static fn (TrustedProxies $proxies): FpmAdapter => new FpmAdapter($proxies),
)->serve();
```

`BrefLambdaAdapter` takes the Runtime API endpoint instead:

```{code-block} php
$adapter = new Kinetis\BrefAdapter\BrefLambdaAdapter(
    (string) getenv('AWS_LAMBDA_RUNTIME_API'),
);
```

To target another environment, implement `RuntimeAdapterInterface` and
hold it to the shared conformance suite — see
{ref}`runtime-reference-custom-adapter`.

## See also

- {doc}`core-concepts` — why application code never needs to know which
  adapter is running it.
- {doc}`concurrency` — what `concurrently()` overlaps under each runtime.
- {doc}`caching` — the production build step, and why it matters most
  under PHP-FPM.
- {doc}`performance-tuning` — the worker × connection budget and tuning
  by workload shape.
- {doc}`appendix-runtime` — request-body staging, forwarded-header
  handling, Lambda event mapping and the custom adapter contract.
- {doc}`appendix` — the framework's runtime namespace.
