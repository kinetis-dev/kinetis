# Tutorial

This tutorial builds a small real-time application from an empty
directory, one working piece at a time: a reply that comes back
immediately, one that comes back later through a queue, one that fires on
its own schedule, and a browser page that watches all three happen live.
Every step leaves you with something you can actually run and test before
moving to the next one.

The finished shape of this tutorial — the same pieces, with a more
developed dashboard in place of this guide's plain log page — ships
ready to run as `kinetis/pingpong`. See
[Starting from `kinetis/pingpong` instead](#starting-from-kinetis-pingpong-instead)
if you'd rather begin from that and modify it.

## What you'll build

A tiny "ping/pong" API:

- `POST /pong/direct` replies in the same request.
- `POST /pong/queued` replies a few seconds later, from a separate worker
  process.
- A scheduled command replies on its own, every few seconds, with no
  request involved at all.
- A browser page watches every one of those happen in real time, over a
  public WebSocket channel, plus a private one it has to authorize
  against first.

Each piece is stored in a database, so `MySQL`, a migration, and the
query builder come first — everything after that builds on having
somewhere to write a row.

## Requirements

- PHP 8.4 or later
- [Composer](https://getcomposer.org)
- Docker, with Compose

```{note}
**PHP version support policy.** Kinetis targets PHP's own currently
*actively supported* minor versions — those still receiving both bug and
security fixes upstream, not the older security-only tail. Today that is
PHP 8.4 and 8.5, tracked by every package's `composer.json` floor and by
the CI matrix (see {doc}`appendix-ci`). As new minors enter active
support and older ones age out, Kinetis's floor and CI move with them:
this is a policy of following PHP's own release lifecycle, not a
commitment to 8.4/8.5 specifically.
```

## Setting up the project

```{code-block} bash
mkdir ping-pong && cd ping-pong
composer init --name=you/ping-pong --type=project --no-interaction
composer require kinetis/framework
```

Add a PSR-4 mapping for your own code to `composer.json`:

```{code-block} json
:caption: composer.json

{
    "require": {
        "kinetis/framework": "^1.0"
    },
    "autoload": {
        "psr-4": {
            "App\\": "src/"
        }
    }
}
```

```{code-block} bash
composer dump-autoload
```

That mapping is not optional. Kinetis finds your controllers, commands
and tools by reading `composer.json`'s own `autoload.psr-4` section — it
never scans the filesystem blindly — so without one there is nothing for
it to look at. The namespace you choose does not matter, only that one is
declared.

```{warning}
Skipping it produces no error. Discovery finds zero routes and logs a
warning naming the gap (`Kinetis\Cache\NamespaceScanner found no PSR-4
root [...] — did you forget an "autoload": {"psr-4": ...} entry in
composer.json?`), but every request still returns a plain `404`, because
nothing was registered to match. If a route below 404s, check this
first, and look for that warning in your error log.
```

## A minimal controller

```{code-block} php
:caption: src/Http/PingController.php

<?php

declare(strict_types=1);

namespace App\Http;

use Kinetis\Http\Attributes\Get;

final readonly class PingController
{
    #[Get('/')]
    public function index(): array
    {
        return ['message' => 'pong'];
    }
}
```

Nothing registers it — any class anywhere under one of your own PSR-4
roots is discovered automatically, with no required directory or
namespace convention. Wire up the entry point every runtime adapter
converges on:

```{code-block} php
:caption: public/index.php

<?php

declare(strict_types=1);

use Kinetis\Container\AppScope;
use Kinetis\Http\Form\FormLimits;
use Kinetis\Http\Kernel;
use Kinetis\Http\Routing\RouteDiscovery;
use Kinetis\Http\TrustedProxies;
use Kinetis\Runtime\ProjectRoot;
use Kinetis\Runtime\RuntimeDetector;

require dirname(__DIR__) . '/vendor/autoload.php';

$projectRoot = ProjectRoot::detect(__DIR__);

$app = new AppScope();
$app->boot();

$router = RouteDiscovery::discover($projectRoot);

// The two policies an adapter needs before the Kernel exists: how many
// bytes a request body may carry, and whose forwarded headers may decide
// its scheme. AppScope::boot() registered both from Config; the adapter
// is handed the same instances the Kernel will enforce.
$adapter = RuntimeDetector::detect($app->get(FormLimits::class), $app->get(TrustedProxies::class));
$kernel = new Kernel($app, $router, isPersistent: $adapter->isPersistent());

$adapter->run($kernel->handle(...));
```

```{tip}
This tutorial keeps `public/index.php` in its plain, always-live-discovery
form throughout — routes and commands are (re-)discovered on every
request, which is the simplest thing to reason about while a project is
this small. {doc}`caching` covers pre-compiling all of this for
production once you actually need it.
```

## Running it

Three files get `docker compose` running PHP-FPM behind nginx, without
needing PHP or Composer installed on the host:

```{code-block} dockerfile
:caption: docker/Dockerfile

FROM php:8.4-fpm-alpine

RUN apk add --no-cache unzip curl-dev $PHPIZE_DEPS \
    && docker-php-ext-install curl \
    && apk del $PHPIZE_DEPS

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Kinetis reads and bounds the request body itself; PHP must not parse
# it first. Required, not tuning — see {doc}`runtime-adapters`.
RUN printf 'enable_post_data_reading=0\n' > /usr/local/etc/php/conf.d/zz-kinetis.ini

COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["php-fpm", "-F"]
```

```{code-block} bash
:caption: docker/entrypoint.sh

#!/bin/sh
set -e

composer install --no-interaction --no-progress

exec "$@"
```

```{code-block} bash
chmod +x docker/entrypoint.sh
```

```{code-block} nginx
:caption: docker/nginx.conf

server {
    listen 8080;

    root /app/public;
    index index.php;

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

```{code-block} yaml
:caption: docker-compose.yml

services:
  app:
    build:
      context: .
      dockerfile: docker/Dockerfile
    volumes:
      - .:/app
      - vendor:/app/vendor

  nginx:
    image: nginx:alpine
    volumes:
      - .:/app
      - ./docker/nginx.conf:/etc/nginx/conf.d/default.conf:ro
    ports:
      - "8080:8080"
    depends_on:
      app:
        condition: service_started

volumes:
  vendor:
```

The `vendor` volume keeps installed dependencies out of your own project
directory, so the container's `composer install` never writes into it
directly. `app` runs PHP-FPM; `nginx` proxies HTTP requests to it over
FastCGI. This split matters beyond just "how do I serve HTTP": PHP-FPM
reboots the whole `public/index.php` script — including route/command
discovery — on every single request, so an edit to a controller takes
effect on your very next request, no restart needed. A persistent-worker
runtime like FrankenPHP or RoadRunner can't offer that (once a class is
loaded in a worker process, PHP has no way to redeclare it with new
content), which is why local development here runs on PHP-FPM rather
than a worker mode — see {doc}`runtime-adapters` for when to reach for
one instead.

```{code-block} bash
docker compose up --build
```

```{code-block} bash
:caption: Try it

curl http://localhost:8080/
# {"message":"pong"}
```

That route already documents itself. Open
`http://localhost:8080/openapi` for a Swagger UI, and
`http://localhost:8080/openapi.json` for the OpenAPI 3.1 document behind
it — both generated from the attributes you just wrote, with nothing to
annotate or keep in step. Every route you add below appears there as you
go. {doc}`routing-validation` covers what the generator reads.

## Storing pings: MySQL, migrations, and the query builder

```{code-block} bash
composer require kinetis/migrations kinetis/query-builder
```

Add a `.env` file at the project root — read by both the app and the
tools you're about to add:

```{code-block} text
:caption: .env

DB_CONNECTION=mysql
DB_HOST=mysql
DB_PORT=3306
DB_NAME=pingpong
DB_USER=pingpong
DB_PASSWORD=pingpong
```

A migration for the table every scenario writes to:

```{code-block} php
:caption: migrations/20260810120000_create_ping_messages_table.php

<?php

declare(strict_types=1);

use Kinetis\Persistence\Contract\MysqlLink;
use Kinetis\Persistence\Contract\PostgresLink;
use Kinetis\Migrations\Migration;

return new class implements Migration
{
    public function up(MysqlLink|PostgresLink $db): void
    {
        $db->execute(<<<'SQL'
            CREATE TABLE ping_messages (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                scenario VARCHAR(20) NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'pending',
                created_at DATETIME NOT NULL,
                ponged_at DATETIME NULL
            )
            SQL);
    }

    public function down(MysqlLink|PostgresLink $db): void
    {
        $db->execute('DROP TABLE ping_messages');
    }
};
```

A small repository wraps reading and writing that table:

```{code-block} php
:caption: src/Repositories/PingRepository.php

<?php

declare(strict_types=1);

namespace App\Repositories;

use Kinetis\Persistence\Contract\MysqlLink;
use Kinetis\QueryBuilder\Query;

final readonly class PingRepository
{
    public function __construct(
        private MysqlLink $db,
    ) {}

    public function create(string $scenario): int
    {
        $id = new Query($this->db)->table('ping_messages')->insertGetId([
            'scenario' => $scenario,
            'status' => 'pending',
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        return (int) $id;
    }

    public function markPonged(int $id): void
    {
        new Query($this->db)->table('ping_messages')->where('id', '=', $id)->update([
            'status' => 'ponged',
            'ponged_at' => date('Y-m-d H:i:s'),
        ]);
    }
}
```

`PingRepository` needs a real `MysqlLink` client registered before the
container locks its bindings — and `kinetis/persistence` does that
itself: its package bootstrap (see {doc}`cli`) reads the `DB_*` keys you
just put in `.env` and binds the connection under `MysqlLink`, with no
wiring of your own. What `public/index.php` does need is to load `.env`,
build a `Config`, and run the bootstrap chain — every installed
package's bootstrap, then your own optional `bootstrap.php` — before it
boots the container. Replace everything up to (and including)
`$app->boot();` with:

```{code-block} php
:caption: public/index.php

use Kinetis\Config\Config;
use Kinetis\Config\EnvFile;

require dirname(__DIR__) . '/vendor/autoload.php';

$projectRoot = ProjectRoot::detect(__DIR__);
EnvFile::safeLoad($projectRoot);

$app = new AppScope();
$config = Config::fromEnvironment();
$app->instance(Config::class, $config);
RoutesFile::loadBootstrap($projectRoot)($app, $config);
$app->boot();
```

The rest of the file — building the `Router`, detecting the runtime
adapter, constructing the `Kernel` — stays exactly as it was.

Now update the controller to actually create and reply to a ping:

```{code-block} php
:caption: src/Http/PingController.php

<?php

declare(strict_types=1);

namespace App\Http;

use App\Repositories\PingRepository;
use Kinetis\Http\Attributes\Post;

final readonly class PingController
{
    public function __construct(
        private PingRepository $pings,
    ) {}

    #[Post('/pong/direct')]
    public function direct(): array
    {
        $id = $this->pings->create('direct');
        $this->pings->markPonged($id);

        return ['id' => $id, 'status' => 'ponged'];
    }
}
```

A database needs a place to run, and a migration needs to run against it
before the app starts serving requests:

```{code-block} yaml
:caption: docker-compose.yml

services:
  app:
    build:
      context: .
      dockerfile: docker/Dockerfile
    volumes:
      - .:/app
      - vendor:/app/vendor
    env_file: .env
    depends_on:
      migrate:
        condition: service_completed_successfully
    entrypoint: []
    command: ["php-fpm", "-F"]

  nginx:
    image: nginx:alpine
    volumes:
      - .:/app
      - ./docker/nginx.conf:/etc/nginx/conf.d/default.conf:ro
    ports:
      - "8080:8080"
    depends_on:
      app:
        condition: service_started

  mysql:
    image: mysql:8.4
    environment:
      MYSQL_DATABASE: pingpong
      MYSQL_USER: pingpong
      MYSQL_PASSWORD: pingpong
      MYSQL_ROOT_PASSWORD: root
    healthcheck:
      test: ["CMD", "mysqladmin", "ping", "-h", "localhost", "-u", "root", "-proot"]
      interval: 5s
      timeout: 5s
      retries: 10

  migrate:
    build:
      context: .
      dockerfile: docker/Dockerfile
    volumes:
      - .:/app
      - vendor:/app/vendor
    env_file: .env
    depends_on:
      mysql:
        condition: service_healthy
    command: ["php", "vendor/bin/kinetis", "migrate"]
    healthcheck:
      disable: true

volumes:
  vendor:
```

`migrate` is the one service that installs dependencies and runs to
completion — `app` now waits for it, with its own `entrypoint` cleared so
it doesn't also try to install into the same shared `vendor` volume at
the same time.

```{code-block} bash
docker compose up --build
```

```{code-block} bash
:caption: Try it

curl -X POST http://localhost:8080/pong/direct
# {"id":1,"status":"ponged"}
```

## Deferring the reply: Redis and the queue

```{code-block} bash
composer require kinetis/queue kinetis/queue-redis
```

```{code-block} text
:caption: .env (additions)

REDIS_HOST=redis
REDIS_PORT=6379

QUEUE_CONNECTION=redis
```

A job that pongs a ping, run later by a worker instead of inline:

```{code-block} php
:caption: src/Queue/PongJob.php

<?php

declare(strict_types=1);

namespace App\Queue;

use App\Repositories\PingRepository;
use Kinetis\Queue\Job;

final readonly class PongJob implements Job
{
    public function __construct(
        public int $id,
    ) {}

    public function handle(PingRepository $pings): void
    {
        $pings->markPonged($this->id);
    }
}
```

Nothing to register: `QUEUE_CONNECTION=redis` in `.env` is the whole
wiring — `kinetis/queue`'s package bootstrap binds `QueueInterface` to a
Redis-backed queue from it, the same way `kinetis/persistence` already
bound `MysqlLink`.

Add a second method that pushes a job instead of ponging inline:

```{code-block} php
:caption: src/Http/PingController.php

<?php

declare(strict_types=1);

namespace App\Http;

use App\Queue\PongJob;
use App\Repositories\PingRepository;
use Kinetis\Http\Attributes\Post;
use Kinetis\Queue\QueueInterface;

final readonly class PingController
{
    public function __construct(
        private PingRepository $pings,
        private QueueInterface $queue,
    ) {}

    #[Post('/pong/direct')]
    public function direct(): array
    {
        $id = $this->pings->create('direct');
        $this->pings->markPonged($id);

        return ['id' => $id, 'status' => 'ponged'];
    }

    #[Post('/pong/queued')]
    public function queued(): array
    {
        $id = $this->pings->create('queued');
        $this->queue->push(new PongJob($id), delaySeconds: 5);

        return ['id' => $id, 'status' => 'pending'];
    }
}
```

Add Redis and a worker process to run the job:

```{code-block} yaml
:caption: docker-compose.yml (additions)

  redis:
    image: redis:7-alpine

  queue-worker:
    build:
      context: .
      dockerfile: docker/Dockerfile
    volumes:
      - .:/app
      - vendor:/app/vendor
    env_file: .env
    depends_on:
      redis:
        condition: service_started
      migrate:
        condition: service_completed_successfully
    entrypoint: []
    command: ["php", "vendor/bin/kinetis", "queue:work"]
    healthcheck:
      disable: true
```

```{code-block} bash
docker compose up --build
```

```{code-block} bash
:caption: Try it

curl -X POST http://localhost:8080/pong/queued
# {"id":2,"status":"pending"}
```

The row stays `pending` for five seconds, then `queue-worker` picks up
the job and marks it `ponged` — check with another request against
whatever endpoint reads it back, or query the database directly.

## Replying on a schedule: a console command

No new package — `Kinetis\Console` ships in core. Any class anywhere
under your own PSR-4 root is discovered automatically — `App\Console` is
just the convention this tutorial keeps using, not a requirement:

```{code-block} php
:caption: src/Console/PongCronCommand.php

<?php

declare(strict_types=1);

namespace App\Console;

use App\Repositories\PingRepository;
use Kinetis\Console\Attributes\Command;

final readonly class PongCronCommand
{
    public function __construct(
        private PingRepository $pings,
    ) {}

    #[Command('pings:pong-cron', description: 'Creates and pongs a cron-driven ping')]
    public function run(): int
    {
        $id = $this->pings->create('cron');
        $this->pings->markPonged($id);

        return 0;
    }
}
```

Kinetis doesn't schedule anything itself — a plain interval loop in its
own container runs the command every five seconds:

```{code-block} yaml
:caption: docker-compose.yml (additions)

  cron:
    build:
      context: .
      dockerfile: docker/Dockerfile
    volumes:
      - .:/app
      - vendor:/app/vendor
    env_file: .env
    depends_on:
      migrate:
        condition: service_completed_successfully
    entrypoint: []
    command: ["sh", "-c", "while true; do php vendor/bin/kinetis pings:pong-cron; sleep 5; done"]
    healthcheck:
      disable: true
```

```{code-block} bash
docker compose up --build
```

Watch `ping_messages` grow a new `cron`-scenario row, already `ponged`,
every five seconds — with no request involved at all.

## Making it real-time: events and kinetis/broadcasting

Three scenarios work independently now. The last piece is watching all
three happen live, in a browser, instead of checking the database by
hand.

```{code-block} bash
composer require kinetis/broadcasting
```

```{code-block} text
:caption: .env (additions)

BROADCAST_DRIVER=pusher
BROADCAST_APP_ID=app-id
BROADCAST_KEY=app-key
BROADCAST_SECRET=app-secret

# Used by the PHP backend to publish, over the docker-compose network.
BROADCAST_HOST=soketi
BROADCAST_PORT=6001
BROADCAST_TLS=false

# Used by the browser to subscribe, from outside the docker-compose
# network — app-level config, not part of kinetis/broadcasting's own
# BROADCAST_* contract, since only the server ever talks to BROADCAST_HOST.
BROADCAST_BROWSER_HOST=localhost
BROADCAST_BROWSER_PORT=6001
```

```{code-block} yaml
:caption: docker-compose.yml (additions)

  soketi:
    image: quay.io/soketi/soketi:1.4-16-debian
    environment:
      SOKETI_DEFAULT_APP_ID: ${BROADCAST_APP_ID:-app-id}
      SOKETI_DEFAULT_APP_KEY: ${BROADCAST_KEY:-app-key}
      SOKETI_DEFAULT_APP_SECRET: ${BROADCAST_SECRET:-app-secret}
    ports:
      - "6001:6001"
```

A plain object to carry "something happened" through the pipeline:

```{code-block} php
:caption: src/Events/ActionEvent.php

<?php

declare(strict_types=1);

namespace App\Events;

final readonly class ActionEvent
{
    public function __construct(
        public string $stage,
        public ?int $id = null,
        public ?string $scenario = null,
    ) {}
}
```

Installing `kinetis/broadcasting` is the entire wiring — its own package
bootstrap reads `BROADCAST_DRIVER` and binds `BroadcasterInterface`
before your own `bootstrap.php` ever runs, the same "nothing to
register" shape `kinetis/persistence` and `kinetis/queue` already have.
There's no publisher class to write and no `bootstrap.php` needed for
this. A listener that republishes every `ActionEvent` it sees just
constructor-injects `Kinetis\Broadcasting\Broadcaster`:

```{code-block} php
:caption: src/Listeners/ActionEventListener.php

<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\ActionEvent;
use Kinetis\Broadcasting\Broadcaster;
use Kinetis\Events\Listener;

final readonly class ActionEventListener
{
    public const string CHANNEL = 'ping-pong';

    public function __construct(
        private Broadcaster $broadcaster,
    ) {}

    #[Listener]
    public function onActionEvent(ActionEvent $event): void
    {
        $this->broadcaster->broadcast(self::CHANNEL, 'action', [
            'stage' => $event->stage,
            'id' => $event->id,
            'scenario' => $event->scenario,
        ]);
    }
}
```

`ActionEventListener` needs nothing registered for it either — any class
anywhere under your own PSR-4 root carrying a `#[Listener]` method is
found automatically.

This is the one place the *hand-built* `public/index.php` this tutorial
has been growing needs a real addition to support events. It is not a
step every Kinetis application needs: the real, framework-managed entry
points — `public/index.php`'s own full reference copy, `bin/kinetis`,
and `kinetis/pingpong`'s own `public/index.php` (see the closing section
of this tutorial) — already discover `EventListenerRegistry` themselves
(live via `EventListenerDiscovery::discover()`, or reconstructed from a
compiled cache via `fromArray()`) and hand the result to
`Kinetis\Cache\BootSequence::run()`, the one piece of shared assembly all
of them delegate to for actually *publishing* it — binding it into the
container, before the bootstrap chain runs, with the right precedence —
with nothing for you to write. This tutorial keeps its own
`public/index.php` in a smaller, hand-assembled form on purpose (see the
tip above), so it never picked either of those two steps up along the
way — this is the point where that gap actually matters, and where it
gets closed by hand instead.

`EventDispatcher` resolves `EventListenerRegistry` through the
container, which means it has to be registered with `$app->instance()`
*before* `boot()` locks bindings, the same requirement `Config` and
anything from `bootstrap.php` already have — and *before*
`RoutesFile::loadBootstrap()` specifically, so `bootstrap.php` (yours, or
a package's) can resolve and augment — or outright replace — whatever's
already bound under that id, rather than have it silently reasserted
afterward (see {doc}`appendix` for the full reasoning, under
`BootSequence`). Skip this and nothing breaks loudly: `EventDispatcher`'s
container resolution silently falls back to an empty
`EventListenerRegistry` instead, so every `dispatch()` call still
"succeeds" — it just never reaches any listener, with no error to tell
you why.

```{code-block} php
:caption: public/index.php

use Kinetis\Events\EventListenerDiscovery;
use Kinetis\Events\EventListenerRegistry;

// ...

$app = new AppScope();
$config = Config::fromEnvironment();
$app->instance(Config::class, $config);
$app->instance(EventListenerRegistry::class, EventListenerDiscovery::discover($projectRoot));
RoutesFile::loadBootstrap($projectRoot)($app, $config);
$app->boot();
```

Now dispatch an `ActionEvent` at each real stage a ping passes through.
`PingRepository::create()` gets one for the write:

```{code-block} php
:caption: src/Repositories/PingRepository.php

<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Events\ActionEvent;
use Kinetis\Persistence\Contract\MysqlLink;
use Kinetis\Events\EventDispatcher;
use Kinetis\QueryBuilder\Query;

final readonly class PingRepository
{
    public function __construct(
        private MysqlLink $db,
        private EventDispatcher $events,
    ) {}

    public function create(string $scenario): int
    {
        $id = new Query($this->db)->table('ping_messages')->insertGetId([
            'scenario' => $scenario,
            'status' => 'pending',
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        $id = (int) $id;

        $this->events->dispatch(new ActionEvent('db', $id));

        return $id;
    }

    public function markPonged(int $id): void
    {
        new Query($this->db)->table('ping_messages')->where('id', '=', $id)->update([
            'status' => 'ponged',
            'ponged_at' => date('Y-m-d H:i:s'),
        ]);
    }
}
```

The controller's two methods each get one for being called, and — since
the browser will now hear about a finished pong over the socket instead
of in the HTTP response — return `void` rather than a body:

```{code-block} php
:caption: src/Http/PingController.php

<?php

declare(strict_types=1);

namespace App\Http;

use App\Events\ActionEvent;
use App\Queue\PongJob;
use App\Repositories\PingRepository;
use Kinetis\Events\EventDispatcher;
use Kinetis\Http\Attributes\Post;
use Kinetis\Queue\QueueInterface;

final readonly class PingController
{
    public function __construct(
        private PingRepository $pings,
        private QueueInterface $queue,
        private EventDispatcher $events,
    ) {}

    #[Post('/pong/direct')]
    public function direct(): void
    {
        $id = $this->pings->create('direct');
        $this->events->dispatch(new ActionEvent('app', $id, 'direct'));

        $this->pings->markPonged($id);
        $this->events->dispatch(new ActionEvent('socket', $id, 'direct'));
    }

    #[Post('/pong/queued')]
    public function queued(): void
    {
        $id = $this->pings->create('queued');
        $this->events->dispatch(new ActionEvent('app', $id, 'queued'));

        $this->queue->push(new PongJob($id), delaySeconds: 5);
    }
}
```

`PongJob` and `PongCronCommand` each get their own stage, plus the same
`socket` announcement once the pong is actually written:

```{code-block} php
:caption: src/Queue/PongJob.php

<?php

declare(strict_types=1);

namespace App\Queue;

use App\Events\ActionEvent;
use App\Repositories\PingRepository;
use Kinetis\Events\EventDispatcher;
use Kinetis\Queue\Job;

final readonly class PongJob implements Job
{
    public function __construct(
        public int $id,
    ) {}

    public function handle(PingRepository $pings, EventDispatcher $events): void
    {
        $events->dispatch(new ActionEvent('queue', $this->id));

        $pings->markPonged($this->id);
        $events->dispatch(new ActionEvent('socket', $this->id, 'queued'));
    }
}
```

```{code-block} php
:caption: src/Console/PongCronCommand.php

<?php

declare(strict_types=1);

namespace App\Console;

use App\Events\ActionEvent;
use App\Repositories\PingRepository;
use Kinetis\Console\Attributes\Command;
use Kinetis\Events\EventDispatcher;

final readonly class PongCronCommand
{
    public function __construct(
        private PingRepository $pings,
        private EventDispatcher $events,
    ) {}

    #[Command('pings:pong-cron', description: 'Creates and pongs a cron-driven ping')]
    public function run(): int
    {
        $id = $this->pings->create('cron');
        $this->pings->markPonged($id);

        $this->events->dispatch(new ActionEvent('cron', $id, 'cron'));
        $this->events->dispatch(new ActionEvent('socket', $id, 'cron'));

        return 0;
    }
}
```

Last, a page the browser can actually open. Add a route for it — `/` is
free again, since `direct()`/`queued()` moved to `/pong/...` earlier.
`index()` goes first in the class, ahead of the two `/pong/...` methods —
this is the route someone opens first, in a browser, not an API
consumer's typical entry point:

```{code-block} php
:caption: src/Http/PingController.php

<?php

declare(strict_types=1);

namespace App\Http;

use App\Events\ActionEvent;
use App\Queue\PongJob;
use App\Repositories\PingRepository;
use Kinetis\Config\Config;
use Kinetis\Events\EventDispatcher;
use Kinetis\Http\Attributes\Get;
use Kinetis\Http\Attributes\Post;
use Kinetis\Http\Responses\HtmlResponse;
use Kinetis\Queue\QueueInterface;
use Psr\Http\Message\ResponseInterface;

final readonly class PingController
{
    public function __construct(
        private PingRepository $pings,
        private QueueInterface $queue,
        private EventDispatcher $events,
        private Config $config,
    ) {}

    #[Get('/')]
    public function index(): ResponseInterface
    {
        $key = $this->config->string('BROADCAST_KEY', 'app-key');
        $host = $this->config->string('BROADCAST_BROWSER_HOST', 'localhost');
        $port = $this->config->int('BROADCAST_BROWSER_PORT', 6001);

        return HtmlResponse::create(<<<HTML
            <!doctype html>
            <script src="https://js.pusher.com/8.4.0/pusher.min.js"></script>
            <button onclick="fetch('/pong/direct', {method: 'POST'})">Direct</button>
            <button onclick="fetch('/pong/queued', {method: 'POST'})">Queued</button>
            <ul id="log"></ul>
            <script>
              var pusher = new Pusher("{$key}", {
                wsHost: "{$host}",
                wsPort: {$port},
                forceTLS: false,
                enabledTransports: ['ws'],
                cluster: 'kinetis'
              });
              pusher.subscribe('ping-pong').bind('action', function (data) {
                var li = document.createElement('li');
                li.textContent = '#' + data.id + ' ' + data.stage + ' (' + data.scenario + ')';
                document.getElementById('log').prepend(li);
              });
            </script>
            HTML);
    }

    #[Post('/pong/direct')]
    public function direct(): void
    {
        $id = $this->pings->create('direct');
        $this->events->dispatch(new ActionEvent('app', $id, 'direct'));

        $this->pings->markPonged($id);
        $this->events->dispatch(new ActionEvent('socket', $id, 'direct'));
    }

    #[Post('/pong/queued')]
    public function queued(): void
    {
        $id = $this->pings->create('queued');
        $this->events->dispatch(new ActionEvent('app', $id, 'queued'));

        $this->queue->push(new PongJob($id), delaySeconds: 5);
    }
}
```

```{code-block} bash
docker compose up --build
```

Open `http://localhost:8080/` and click either button. Each click logs
`app` immediately, then `db`, then — for a queued ping — `queue` and
`socket` a few seconds later once the worker picks it up. Leave the page
open and watch a `cron`/`socket` pair appear on its own every five
seconds, with nobody clicking anything.

## Authorizing a private channel

Every message above went over a public channel — anyone who knows the
channel name can subscribe. A **private** channel additionally requires
a signed authorization: the client calls `POST /broadcasting/auth`
before it's allowed to join, and that endpoint needs a real identity to
authorize against.

`kinetis/broadcasting` doesn't ship an identity of its own — it reads
`Kinetis\Http\CurrentUserInterface` off the request scope, the same
contract a real auth package (see {doc}`auth` or {doc}`auth-jwt`) would
register from its own middleware. This tutorial has no login, so bind a
single, fixed visitor instead:

```{code-block} php
:caption: src/Broadcasting/DemoVisitor.php

<?php

declare(strict_types=1);

namespace App\Broadcasting;

use Kinetis\Http\CurrentUserInterface;

final readonly class DemoVisitor implements CurrentUserInterface
{
    public function id(): string
    {
        return 'visitor';
    }
}
```

```{code-block} php
:caption: bootstrap.php

<?php

declare(strict_types=1);

use App\Broadcasting\DemoVisitor;
use Kinetis\Config\Config;
use Kinetis\Container\AppScope;
use Kinetis\Http\CurrentUserInterface;

return static function (AppScope $app, Config $config): void {
    $app->instance(CurrentUserInterface::class, new DemoVisitor());
};
```

An `#[BroadcastChannel]` method authorizes the channel — discovered the
same way as everything else, nothing to register:

```{code-block} php
:caption: src/Broadcasting/NotificationChannelAuthorizer.php

<?php

declare(strict_types=1);

namespace App\Broadcasting;

use Kinetis\Broadcasting\Attributes\BroadcastChannel;
use Kinetis\Http\CurrentUserInterface;

final class NotificationChannelAuthorizer
{
    #[BroadcastChannel('notifications')]
    public function authorize(CurrentUserInterface $user): bool
    {
        return true;
    }
}
```

The pattern names the channel **without** its `private-` prefix — see
{doc}`broadcasting` for the full authorization rules. `POST
/broadcasting/auth` itself needs no route or controller of your own:
installing `kinetis/broadcasting` already registers it, discovered the
same way `RouteDiscovery` finds `PingController`.

`ActionEventListener` broadcasts a second, private notification whenever
a ping is actually ponged:

```{code-block} php
:caption: src/Listeners/ActionEventListener.php

<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\ActionEvent;
use Kinetis\Broadcasting\Broadcaster;
use Kinetis\Events\Listener;

final readonly class ActionEventListener
{
    public const string PUBLIC_CHANNEL = 'ping-pong';

    public const string PRIVATE_CHANNEL = 'private-notifications';

    public function __construct(
        private Broadcaster $broadcaster,
    ) {}

    #[Listener]
    public function onActionEvent(ActionEvent $event): void
    {
        $this->broadcaster->broadcast(self::PUBLIC_CHANNEL, 'action', [
            'stage' => $event->stage,
            'id' => $event->id,
            'scenario' => $event->scenario,
        ]);

        if ($event->stage === 'socket') {
            $this->broadcaster->broadcast(self::PRIVATE_CHANNEL, 'pong.notified', [
                'id' => $event->id,
                'scenario' => $event->scenario,
            ]);
        }
    }
}
```

Last, the browser subscribes to it — `authEndpoint` is the one new
option; pusher-js calls it automatically before a `private-`/`presence-`
subscription is allowed through:

```{code-block} php
:caption: src/Http/PingController.php (additions)

            <ul id="log"></ul>
            <ul id="private-log"></ul>
            <script>
              var pusher = new Pusher("{$key}", {
                wsHost: "{$host}",
                wsPort: {$port},
                forceTLS: false,
                enabledTransports: ['ws'],
                cluster: 'kinetis',
                authEndpoint: '/broadcasting/auth'
              });
              pusher.subscribe('ping-pong').bind('action', function (data) {
                var li = document.createElement('li');
                li.textContent = '#' + data.id + ' ' + data.stage + ' (' + data.scenario + ')';
                document.getElementById('log').prepend(li);
              });
              pusher.subscribe('private-notifications').bind('pong.notified', function (data) {
                var li = document.createElement('li');
                li.textContent = '#' + data.id + ' your ping was ponged';
                document.getElementById('private-log').prepend(li);
              });
            </script>
```

```{code-block} bash
docker compose up --build
```

Click either button again. The public log still fills as before; the
new `private-log` list only fills once the subscription above has
actually been authorized — open the browser console and you'll see the
`POST /broadcasting/auth` request that made it possible.

## Reporting statistics as a typed value

Every scenario writes a row to `ping_messages`. A repository method that
reports how many landed in each scenario is a good place to return
something more structured than a bare array:

```{code-block} php
:caption: src/Dto/ScenarioCounts.php

<?php

declare(strict_types=1);

namespace App\Dto;

final readonly class ScenarioCounts
{
    /**
     * @param array<string, int> $counts
     */
    public function __construct(
        public int $total,
        public array $counts,
    ) {}
}
```

```{code-block} php
:caption: src/Repositories/PingRepository.php (additions)

use App\Dto\ScenarioCounts;

use function Kinetis\Async\concurrently;

private const array SCENARIOS = ['direct', 'queued', 'cron'];

public function countByScenario(): ScenarioCounts
{
    $tasks = [fn () => new Query($this->db)->table('ping_messages')->count()];

    foreach (self::SCENARIOS as $scenario) {
        $tasks[] = fn () => new Query($this->db)->table('ping_messages')->where('scenario', '=', $scenario)->count();
    }

    $results = concurrently($tasks);
    $total = array_shift($results);

    return new ScenarioCounts($total, array_combine(self::SCENARIOS, $results));
}
```

The total and each scenario's count are four independent queries — none
needs another's result — so they run through `concurrently()` instead of
one after another, completing in roughly the time the slowest single
query takes rather than their sum.

`countByScenario()` builds `ScenarioCounts` with a plain `new`, not
`Hydrator::hydrate()`. `Hydrator` casts and validates data crossing an
HTTP request body or an MCP tool call — data you don't control yet. Here,
`$total` and `$counts` are values this same method just computed from its
own query results, already trusted; there's nothing to validate, so a
constructor call is all it needs.

Add a route that returns it:

```{code-block} php
:caption: src/Http/PingController.php (additions)

use App\Dto\ScenarioCounts;

#[Get('/pong/tally')]
public function tally(): ScenarioCounts
{
    return $this->pings->countByScenario();
}
```

A returned DTO encodes to JSON exactly like an equivalent array would —
nothing else changes for `tally()` to reach a client as normal JSON.

```{code-block} bash
:caption: Try it

curl http://localhost:8080/pong/tally
# {"total":3,"counts":{"direct":1,"queued":1,"cron":1}}
```

## Exposing it to an AI agent: an MCP tool

The MCP server is its own package:

```{code-block} bash
composer require kinetis/mcp
```

That one install registers the `kinetis mcp:serve` command and the
`/mcp` HTTP endpoint — nothing else to wire. The same statistics can
then answer a question for an AI agent, through an `#[McpTool]` method
instead of a route attribute. A slightly richer
response — a percentage alongside each count — is a good excuse to nest
one more DTO inside another:

```{code-block} php
:caption: src/Dto/ScenarioStat.php

<?php

declare(strict_types=1);

namespace App\Dto;

final readonly class ScenarioStat
{
    public function __construct(
        public int $count,
        public float $percentage,
    ) {}
}
```

```{code-block} php
:caption: src/Dto/PingScenarioBreakdown.php

<?php

declare(strict_types=1);

namespace App\Dto;

final readonly class PingScenarioBreakdown
{
    /**
     * @param array<string, ScenarioStat> $byScenario
     */
    public function __construct(
        public int $total,
        public array $byScenario,
    ) {}
}
```

```{code-block} php
:caption: src/Mcp/PingStatsToolController.php

<?php

declare(strict_types=1);

namespace App\Mcp;

use App\Dto\PingScenarioBreakdown;
use App\Dto\ScenarioStat;
use App\Repositories\PingRepository;
use Kinetis\Mcp\Attributes\McpTool;

final readonly class PingStatsToolController
{
    public function __construct(
        private PingRepository $pings,
    ) {}

    #[McpTool(
        name: 'ping_scenario_breakdown',
        description: 'Reports how many ping messages came from each scenario (direct, queued, cron) and what percentage of the total each represents',
    )]
    public function pingScenarioBreakdown(): PingScenarioBreakdown
    {
        $counts = $this->pings->countByScenario();
        $byScenario = [];

        foreach ($counts->counts as $scenario => $count) {
            $byScenario[$scenario] = new ScenarioStat(
                $count,
                $counts->total > 0 ? round($count / $counts->total * 100, 1) : 0.0,
            );
        }

        return new PingScenarioBreakdown($counts->total, $byScenario);
    }
}
```

Like `PingController`, nothing registers this class — any class under one
of your own PSR-4 roots carrying an `#[McpTool]` method is discovered
automatically.

The tool still needs a transport to reach a client over. This
application already serves HTTP, and `kinetis/mcp` contributes `POST
/mcp` as an ordinary discovered route, so the Streamable HTTP transport
is already there — no extra process, no extra container, and nothing to
pass to `Kernel`.

What the endpoint needs is a caller it can identify. `/mcp` runs the
`mcp` middleware group, whose last member answers `401` for any request
reaching it with no `Kinetis\Http\CurrentUserInterface` on the request
scope. `DemoVisitor` is bound application-wide in `bootstrap.php`, so it
satisfies that check on every route, `/mcp` included — a fixed visitor
is an identity, not authentication. Give the endpoint a credential of
its own, joining the group by attribute the way everything else here is
discovered:

```{code-block} php
:caption: src/Http/McpAuthMiddleware.php

<?php

declare(strict_types=1);

namespace App\Http;

use App\Broadcasting\DemoVisitor;
use Kinetis\Config\Config;
use Kinetis\Container\RequestScope;
use Kinetis\Http\Attributes\AsMiddlewareGroup;
use Kinetis\Http\CurrentUserInterface;
use Kinetis\Http\Responses\ErrorResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

#[AsMiddlewareGroup('mcp')]
final readonly class McpAuthMiddleware implements MiddlewareInterface
{
    public function __construct(
        private Config $config,
        private RequestScope $scope,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $token = $this->config->string('MCP_TOKEN', '');

        if ($token === '' || !hash_equals('Bearer ' . $token, $request->getHeaderLine('Authorization'))) {
            return ErrorResponse::create(401, 'Unauthenticated.');
        }

        $this->scope->instance(CurrentUserInterface::class, new DemoVisitor());

        return $handler->handle($request);
    }
}
```

```{code-block} text
:caption: .env (additions)

MCP_TOKEN=local-mcp-token
```

The group runs it at the attribute's default priority `50`: after the
package's own `Origin` validation at `100`, and before the identity
guard at `0`, which the `CurrentUserInterface` it registers satisfies.
A shared secret is the smallest thing that authenticates a
caller; an application with real users puts `kinetis/auth`'s or
`kinetis/auth-jwt`'s middleware in the group instead, and the identity
it resolves reaches the tool the same way — see {doc}`mcp`'s "Securing
the HTTP transport".

A request also mirrors its protocol version and method into headers, and
the server rejects one whose header and body disagree:

```{code-block} bash
:caption: Try it

curl -X POST http://localhost:8080/mcp \
    -H "Authorization: Bearer local-mcp-token" \
    -H "Content-Type: application/json" \
    -H "MCP-Protocol-Version: 2026-07-28" \
    -H "Mcp-Method: tools/list" \
    -d '{
        "jsonrpc": "2.0",
        "id": 1,
        "method": "tools/list",
        "params": {
            "_meta": {
                "io.modelcontextprotocol/protocolVersion": "2026-07-28",
                "io.modelcontextprotocol/clientCapabilities": {}
            }
        }
    }'
```

That confirms `ping_scenario_breakdown` is reachable over `/mcp`. To ask a
real question through it from Claude Code's CLI, register the endpoint as
an MCP server, carrying the same token:

```{code-block} bash
claude mcp add --transport http --scope user ping-pong http://localhost:8080/mcp \
    --header "Authorization: Bearer local-mcp-token"
```

Claude Code reads its list of MCP servers once, at session start, so
restart your session after adding (or removing) one before it takes
effect. Once it's connected, ask something the application itself has to
answer — not something Claude already knows:

```{code-block} text
using ping-pong, what percentage of pings are cron-triggered?
```

Claude Code calls `ping_scenario_breakdown` over `/mcp`, reads back
whatever `PingRepository` actually has in the database at that moment,
and answers from that.

## Recap

Four independent scenarios — an immediate reply, a delayed one, a
scheduled one, and a live view of all three — built up one working piece
at a time: a controller, a repository backed by a real database, a queued
job, a scheduled command, and an event published to a browser over a
public and a private WebSocket channel. Nothing here is scenario-specific
plumbing either — the same `bootstrap.php` convention, the same query
builder, the same queue and event dispatcher, apply to any Kinetis
application. The same repository also fed a typed DTO to an HTTP route
and, unchanged, to an MCP tool an AI agent can call directly over the
same server.

(starting-from-kinetis-pingpong-instead)=
## Starting from `kinetis/pingpong` instead

`kinetis/pingpong` is this same application, already built, with a more
developed dashboard in place of the plain log page above. To start a new
project from it instead of building one up by hand:

```{code-block} bash
composer create-project kinetis/pingpong my-app
cd my-app
cp .env.example .env
docker compose up --build
```

Everything from this tutorial — `bootstrap.php`, the migration, the
repository, the job, the scheduled command, the events, the broadcaster
and its private-channel authorizer, the statistics DTOs, and the MCP
tool — is already there, under the same file layout this tutorial used,
ready to read through and modify directly.

Unlike this tutorial's own PHP-FPM setup above, `kinetis/pingpong`'s
`docker-compose.yml` runs `app` under a genuine FrankenPHP persistent
worker — Kinetis's *primary optimization target*. One side effect worth
knowing: a FrankenPHP worker loads `public/index.php` (including all
discovery) exactly once at boot, so a code change needs an `app`
container restart to take effect — there's no PHP-FPM-style hot reload
on every request; RoadRunner's own worker processes have the identical
limitation. See {doc}`runtime-adapters` for more on when to reach for a
persistent-worker runtime.

## See also

- {doc}`core-concepts` — why a persistent worker changes the rules, and
  what the request lifecycle you just used actually does.
- {doc}`container` — `AppScope` and `RequestScope`, and why Kinetis bans
  `static` properties.
- {doc}`routing-validation` — the full attribute vocabulary, validation
  constraints, and the OpenAPI generator.
- {doc}`config` — `.env` loading, typed `Config` access, and
  `bootstrap.php` in full.
- {doc}`persistence` — connecting to MySQL, Postgres, and Redis directly.
- {doc}`concurrency` — `concurrently()` in full, including what happens
  when one of several concurrent tasks fails.
- {doc}`migrations` — the migration runner used above, in full.
- {doc}`query-builder` — the query builder used above, in full.
- {doc}`queue` — the job queue used above, including multiple workers,
  named queues, and retry limits.
- {doc}`events` — the event dispatcher used above, including stopping
  propagation and deferring a listener onto a queue.
- {doc}`broadcasting` — the broadcaster, `ShouldBroadcast`, and
  private/presence channel authorization used above, in full.
- {doc}`mcp` — tools and resources, transports, and progress notifications
  in full.
- {doc}`cli` — how `#[Command]` classes are discovered, and `kinetis build`
  for pre-compiling everything ahead of time in production.
- {doc}`caching` — pre-compiling routes, commands, and validation ahead of
  time for production.
