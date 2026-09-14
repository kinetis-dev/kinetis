# ORM

````{note}
Not part of core. Install it separately:

```{code-block} sh
composer require kinetis/orm
```

`kinetis/orm` depends on `kinetis/query-builder` and
`kinetis/persistence`, never on `kinetis/framework`. Its contract — the
mapping rules, the admitted row values, the identity map, the repository
and query API, and what it does not do — is the
[package README](https://github.com/kinetis-dev/orm#readme). This page
covers setting it up.
````

A read-side data mapper over {doc}`query-builder`: classes marked
`#[Entity]` load through typed repositories and entity queries, without
running their constructors, and each unit of work holds one object per
row. It reads only: no writes, relationships or change tracking.

## In a Kinetis application

With `kinetis/database-bridge` installed and `DB_CONNECTION` configured
({doc}`persistence`), installing `kinetis/orm` is the whole wiring. A
controller or service in the request scope injects `EntityManager`:

```{code-block} php
use Kinetis\Http\Attributes\Get;
use Kinetis\Orm\EntityManager;

final readonly class ArticleController
{
    public function __construct(private EntityManager $entities) {}

    #[Get('/articles/{id}')]
    public function show(int $id): array
    {
        $article = $this->entities->repository(Article::class)->findOrFail($id);

        return ['title' => $article->title()];
    }
}
```

`Article` is an application entity, such as the one in the README.

### Entity discovery

The bridge declares `Kinetis\DatabaseBridge\OrmMetadata` as its
`extra.kinetis` discovery class (see {doc}`cli`). Its compile step scans
the project's PSR-4 roots and every installed package's `scan` roots,
keeps the classes carrying `#[Entity]`, and maps exactly those with
`MetadataRegistry`. The result is one section of the AOT artifact
({doc}`caching`): development discovers entities live at each boot, and
production reads what `kinetis build` compiled. No environment key
narrows the scan.

An entity the mapper refuses fails `kinetis build`, or a development boot,
with its `MappingException`. A cached section that no longer matches the
entity classes, or that was compiled before `kinetis/orm` was installed
or removed, is stale: the boot compiles fresh, as for any other section.

### Lifecycle

- `Kinetis\Orm\OrmFactory` is bound on `AppScope`, one per worker. It is
  built on first use from the link bound under `MysqlLink` or
  `PostgresLink` — an application's own binding of it in `bootstrap.php`
  included — and the compiled metadata.
- `Kinetis\Orm\EntityManager` is bound on every `RequestScope`: an HTTP
  request, a queued job, an MCP message, a command. The first resolution
  in a scope opens it, owned by the Fiber resolving it, and registers its
  `close()` on that scope's disposal. A scope that never resolves it opens
  none. Sequential and concurrent units of work never share a manager or
  an entity.
- Disposal closes the manager and leaves the connection open.
- `EntityManager` is never an `AppScope` service. Its constructor is not
  public, so `AppScope` refuses to autowire one rather than keep a manager
  for the life of the worker. Code that holds a manager is request-scoped.

### Without a database

With `kinetis/orm` installed and `DB_CONNECTION` unset, the application
still boots. Resolving `OrmFactory` or `EntityManager` throws
`Kinetis\DatabaseBridge\Exception\DatabaseNotConfiguredException`, which
names `DB_CONNECTION`.

### Other connections and transactions

The bridge wires the default connection only. For a named connection,
build a factory once from
`ConnectionFactory::fromConfig($config, 'reporting')` and a
`MetadataRegistry`, and pair each `open()` with `close()` in the unit of
work that reads through it, as in the next section.

An ORM read never joins a transaction: see the README's "Transactions".

## Without Kinetis

```{code-block} php
use Kinetis\Orm\Metadata\MetadataRegistry;
use Kinetis\Orm\OrmFactory;
use Kinetis\Persistence\ConnectionDefinition;
use Kinetis\Persistence\SqlConnectionFactory;

// Once per process.
$db = SqlConnectionFactory::create(new ConnectionDefinition(
    dialect: 'pgsql',
    host: 'db.internal',
    database: 'shop',
    user: 'shop',
    password: $password,
));
$orm = OrmFactory::create($db, MetadataRegistry::fromClasses([Article::class]));

// Once per unit of work.
$entities = $orm->open();

try {
    $published = $entities->repository(Article::class)->findBy(['status' => ArticleStatus::Published]);
} finally {
    $entities->close();
}
```

The host builds the client and the factory once and closes the client at
shutdown. `MetadataRegistry::toArray()` and `fromArray()` let a build step
export the metadata so a worker loads it without reflecting a directory.

## See also

- {doc}`query-builder` — the builder every entity query composes, and
  what `EntityQuery::builder()` returns.
- {doc}`persistence` — the connection, pooling and `TransactionGuard`.
- {doc}`caching` — the AOT artifact the entity metadata is compiled into.
