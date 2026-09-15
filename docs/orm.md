# ORM

````{note}
Not part of core. Install it with `kinetis/database-bridge`, which wires
it into a Kinetis application:

```{code-block} sh
composer require kinetis/orm kinetis/database-bridge
```

`kinetis/orm` depends on `kinetis/query-builder` and
`kinetis/persistence`, never on `kinetis/framework`. Its contract — the
mapping rules, identifiers, the admitted row values, the identity map,
the repository and query API, relationships, writing and flushing,
optimistic locking, transaction sessions, and what it does not do — is
the [package README](https://github.com/kinetis-dev/orm#readme). This
page covers using it in an application.
````

A data mapper over {doc}`query-builder`: classes marked `#[Entity]` load
through typed repositories and entity queries without running their
constructors, and each unit of work holds one object per row, tracks
changes to it, and writes new, changed and removed entities in one
transaction on `flush()`. Entities reference each other through explicit
`#[BelongsTo]`, `#[HasOne]` and `#[HasMany]` relationships, and a
`#[Version]` property adds optimistic locking.

## Define an entity

With `DB_CONNECTION` configured ({doc}`persistence`), an entity class is
all the wiring:

```{code-block} php
use Kinetis\Orm\Attributes\Column;
use Kinetis\Orm\Attributes\Entity;

#[Entity(table: 'articles')]
final class Article
{
    private int $id;

    private string $title;

    private ArticleStatus $status; // enum ArticleStatus: string

    #[Column(name: 'author')]
    private int $authorId;

    public function __construct(int $id, string $title, int $authorId)
    {
        // Application invariants. Loading an Article never runs this.
        $this->id = $id;
        $this->title = $title;
        $this->authorId = $authorId;
        $this->status = ArticleStatus::Draft;
    }

    public function title(): string
    {
        return $this->title;
    }

    public function publish(): void
    {
        $this->status = ArticleStatus::Published;
    }
}
```

Every non-static property but an inverse relationship maps a column,
named in snake case unless `#[Column]` names it, and the property named
`id` (or carrying `#[Id]`) is the identifier. The README's "Entities" lists the admitted property
types and every mapping refusal. Create the table with a migration
({doc}`migrations`).

### Entity discovery

The bridge declares `Kinetis\DatabaseBridge\OrmMetadata` as its
`extra.kinetis` discovery class (see {doc}`cli`). Its compile step scans
the project's PSR-4 roots and every installed package's `scan` roots,
keeps the classes carrying `#[Entity]`, and maps exactly those with
`MetadataRegistry`. The result is one section of the AOT artifact
({doc}`caching`): development discovers entities live at each boot, and
production reads what `kinetis build` compiled. No environment key
narrows the scan.

An entity the mapper refuses fails `kinetis build`, or a development
boot, with its `MappingException`. A cached section that no longer
matches the entity classes, or that was compiled before `kinetis/orm`
was installed or removed, is stale: the boot compiles fresh, as for any
other section.

An entity opts into optimistic locking with `#[Version]` under the
README's "Optimistic locking" contract, and declares relationships with
`#[BelongsTo]`, `#[HasOne]` and `#[HasMany]` under its "Relationships"
contract; the bridge adds nothing to either. A relationship's target
must be an entity the same scan finds. Every one of these attributes is
part of the compiled metadata, so run `kinetis build` again after
adding, removing or moving one.

## Use the request's `EntityManager`

A controller or service in the request scope injects `EntityManager`:

```{code-block} php
use Kinetis\Http\Attributes\Get;
use Kinetis\Http\Attributes\Post;
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

    #[Post('/articles/{id}/publish')]
    public function publish(int $id): array
    {
        $article = $this->entities->repository(Article::class)->findOrFail($id);
        $article->publish();
        $this->entities->flush();

        return ['title' => $article->title()];
    }
}
```

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
- `EntityManager` is never an `AppScope` service. Its constructor is not
  public, so `AppScope` refuses to autowire one rather than keep a manager
  for the life of the worker. Code that holds a manager is request-scoped.

### Repositories and queries

```{code-block} php
$articles = $this->entities->repository(Article::class); // EntityRepository<Article>

$articles->find(42);        // ?Article
$articles->findOrFail(42);  // Article, or EntityNotFoundException
$articles->findBy(['authorId' => 7, 'status' => ArticleStatus::Published]); // list<Article>

$page = $articles->query()  // EntityQuery<Article>
    ->where('status', '=', ArticleStatus::Published)
    ->orderBy('id', 'DESC')
    ->paginate(perPage: 20, page: 2);
```

Queries name properties, not columns, and convert values through each
property's type before any SQL. `get()` and `findBy()` buffer every
matching row, so page through a result that can grow with
`cursorPaginate()`. `with()` loads relationships, and `builder()` returns
a plain `Query` on the manager's link for SQL the entity API does not
cover. The README's "Repositories and queries" and "Relationships" give
the complete API. `EntityRepository` is final: an application repository
wraps it and injects `EntityManager`, which makes it request-scoped too.

(orm-flush-outcomes)=
## Flushing and transactions

Nothing is flushed for you. Code that changes entities calls `flush()`
before its unit of work ends; disposal closes the manager, abandons
whatever was not flushed, and leaves the connection open.

`flush()` on the request's manager writes every pending insert, change
and deletion in one transaction:

- **It returns: committed.** The flush sent `COMMIT` and the server
  acknowledged it before `flush()` returned.
- **Nothing pending: nothing sent.** A `flush()` with nothing to write
  opens no transaction and does no I/O.
- **It throws before `COMMIT`: nothing kept.** The database kept nothing
  of the flush, and every pending change is still pending, so `flush()`
  can run again once the cause is fixed. It never retries by itself.
  `RollbackFailedException` closes the manager instead.
- **`UnknownFlushOutcomeException`: unknown.** `COMMIT` was sent and the
  call failed. The database may or may not hold the work, and the manager
  is closed.

```{warning}
After `UnknownFlushOutcomeException`, establish what the database holds
through a new unit of work before doing the work again. Replaying it as
though it failed can apply it twice.
```

The README's "When a flush fails" is the full failure table.

### Locking rows, or entities and SQL in one transaction

The request's manager never joins a transaction. Do not use it inside a
`TransactionGuard::transaction()` callback, or anywhere the Fiber holds a
transaction on the same connection: its reads are refused there, and its
`flush()` would need a second connection and could wait on locks the
first transaction holds. Work that locks entity rows, or writes entities
and query-builder SQL together, injects `OrmFactory` and runs in
`transaction()`, using the manager the callback receives:

```{code-block} php
use Kinetis\Http\Attributes\Post;
use Kinetis\Orm\EntityManager;
use Kinetis\Orm\OrmFactory;

final readonly class ShipmentController
{
    public function __construct(private OrmFactory $orm) {}

    #[Post('/orders/{id}/ship')]
    public function ship(int $id): array
    {
        $shipped = $this->orm->transaction(function (EntityManager $entities) use ($id): bool {
            $order = $entities->repository(Order::class)
                ->query()
                ->where('id', '=', $id)
                ->lockForUpdate()
                ->first();

            if ($order === null) {
                return false;
            }

            $order->ship();
            $entities->builder()->table('order_events')->insert(['order_id' => $id, 'event' => 'shipped']);
            $entities->flush(); // the final ORM operation: COMMIT follows the callback's return

            return true;
        });

        return ['shipped' => $shipped];
    }
}
```

`Order` is the README's "Optimistic locking" entity, and `order_events`
an application table.

- **Committed only when `transaction()` returns.** A `flush()` on the
  session's manager sends every statement but `COMMIT`: its writes are
  provisional until the callback returns and the factory's `COMMIT` is
  acknowledged. Generated keys, versions and snapshots are applied only
  then.
- **One writing flush, last.** After a flush that writes, the manager
  refuses every call but `close()` and `isClosed()` until the callback
  returns. A callback that returns without `flush()` commits no ORM
  change, and a `flush()` with nothing pending sends nothing.
- **A failure rolls back.** An ORM failure or a callback exception rolls
  the transaction back and sends no `COMMIT`. The first ORM failure is
  rethrown even when the callback caught it, and a rollback that fails
  too throws `RollbackFailedException`.
- **`CommitNotAcknowledgedException`: unknown.** No acknowledged `COMMIT`
  came back. Do not assume the work failed and do not replay it;
  establish what the database holds first.
- **Detached afterwards.** On every way out the manager is closed and its
  entities detached, so an entity the callback returns is a plain object.

The README's "Transaction sessions" and "When a session fails" give the
complete contract.

## Without a database

With `kinetis/orm` installed and `DB_CONNECTION` unset, the application
still boots. Resolving `OrmFactory` or `EntityManager` throws
`Kinetis\DatabaseBridge\Exception\DatabaseNotConfiguredException`, which
names `DB_CONNECTION`.

## Other connections

The bridge wires the default connection only. For a named connection
({ref}`database-reference-registration`), build a factory once from its
link and a `MetadataRegistry`, and pair each `open()` with `close()` in
the unit of work that uses it, as {ref}`database-reference-standalone`
shows.

## See also

- {ref}`testing-orm` — a new manager for each test unit and request that
  resolves the ORM, and what a test may assert after each `COMMIT`
  outcome.
- {doc}`query-builder` — the builder every entity query composes, and
  what `EntityManager::builder()` returns.
- {doc}`persistence` — the connection and `TransactionGuard`.
- {doc}`appendix-database` — transaction and session contracts behind
  ORM operations.
- {doc}`caching` — the AOT artifact the entity metadata is compiled into.
