<p align="center">
  <img src="logo.svg" alt="Kinetis" width="420">
</p>

<p align="center">
  <strong>kinetis/orm</strong>
  <br>
  <strong>A data mapper and unit of work for MySQL and PostgreSQL</strong>
</p>

<p align="center">
  <a href="https://packagist.org/packages/kinetis/orm"><img src="https://img.shields.io/packagist/v/kinetis/orm?label=version" alt="Packagist Version"></a>
  <a href="https://packagist.org/packages/kinetis/orm"><img src="https://img.shields.io/packagist/dt/kinetis/orm" alt="Packagist Downloads"></a>
  <a href="https://packagist.org/packages/kinetis/orm"><img src="https://img.shields.io/packagist/php-v/kinetis/orm" alt="PHP Version"></a>
  <a href="https://packagist.org/packages/kinetis/orm"><img src="https://img.shields.io/packagist/l/kinetis/orm" alt="License"></a>
  <a href="https://github.com/kinetis-dev/kinetis/actions/workflows/ci.yml"><img src="https://github.com/kinetis-dev/kinetis/actions/workflows/ci.yml/badge.svg" alt="CI"></a>
</p>

---

Part of [Kinetis](https://kinetis.dev/), a non-blocking PHP framework for
API-first applications, developed in the
[kinetis-dev/kinetis](https://github.com/kinetis-dev/kinetis) monorepo.
Usable standalone: in production it depends only on
[`kinetis/query-builder`](https://github.com/kinetis-dev/query-builder)
and [`kinetis/persistence`](https://github.com/kinetis-dev/persistence).

Plain PHP classes marked `#[Entity]` are mapped into portable metadata,
loaded through typed repositories and entity queries, and hydrated
without their constructors. Each unit of work holds one object per row,
tracks changes to the entities it holds, and writes new, changed and
removed entities in one transaction when it is flushed. Updates and
deletes of an entity carrying `#[Version]` are optimistically locked. It
has no relationships.

This README is the package's contract. How a Kinetis application wires
it: [kinetis.dev/docs/orm.html](https://kinetis.dev/docs/orm.html).

## Entities

```php
use Kinetis\Orm\Attributes\Column;
use Kinetis\Orm\Attributes\Entity;

#[Entity(table: 'articles')]
final class Article
{
    private int $id;

    private string $title;

    private ?string $summary = null;

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

- **Table.** `#[Entity]` is required. Without `table`, the table is the
  class's short name in snake case, singular: `ArticleCategory` maps to
  `article_category`, so a plural table is named as above. A table name
  is one or more identifiers separated by dots (`reporting.articles`).
- **Columns.** Every non-static property is mapped, trait properties
  included. A column is the property name in snake case (`publishedAt`
  maps to `published_at`) unless `#[Column(name: ...)]` names it. A column
  name is one identifier: ASCII letters, digits and underscores, not
  starting with a digit. Two columns differing only by case are refused.
- **Identifier.** The property carrying `#[Id]`, or else the property
  named exactly `id`. There is exactly one, typed `int` or `string`,
  assigned by the application unless the database generates it (see
  "Identifiers").
- **Version.** Optional: the one property carrying `#[Version]`, typed
  `int` and not the identifier, opts the entity into optimistic locking
  (see "Optimistic locking"). A property merely named `version` is
  ordinary data.
- **Types.** `string`, `int`, `float`, `bool`, a backed enum, and the
  nullable form of each.
- **Classes.** An entity has no parent class and is neither abstract nor
  readonly; it may be final. Its constructor's signature and visibility
  do not matter.

`MappingException` refuses, when the metadata is built and before any
SQL: a class without `#[Entity]`, a readonly class or property, a hooked
or virtual property, an untyped property, a union other than a nullable
type, an intersection, any other type (`mixed`, `array`, an object,
`DateTimeImmutable`, a unit enum), a missing or second identifier, a
generated identifier not typed `?int`, a second `#[Version]`, a version
property that is the identifier or is not typed `int`, an invalid name
and a duplicate column.

## Identifiers

```php
use Kinetis\Orm\Attributes\Entity;
use Kinetis\Orm\Attributes\Id;

#[Entity(table: 'tickets')]
final class Ticket
{
    #[Id(generated: true)]
    private ?int $id = null;

    public function __construct(private string $subject) {}

    public function id(): ?int
    {
        return $this->id;
    }
}
```

- **Assigned**, the default. The application sets a non-null `int` or
  `string` before `persist()`. A string identifier covers
  application-generated UUID text stored in a PostgreSQL `uuid` or a
  MySQL/MariaDB `CHAR(36)` column; this package does not generate,
  normalize or validate UUIDs. The property may be nullable, but
  `persist()` refuses null, and so does a loaded row.
- **Generated.** `#[Id(generated: true)]` on a property typed `?int`,
  over a column the database fills: a MySQL/MariaDB `AUTO_INCREMENT` or a
  PostgreSQL identity column. The property holds null until the entity's
  insert commits. The INSERT leaves the column out, and the key the
  database reports — through `RETURNING` on PostgreSQL, as the insert id
  on the MySQL family — must be an int within PHP's range.
- An identifier does not change while a manager holds its entity:
  `flush()` refuses one that did.

## Metadata

```php
use Kinetis\Orm\Metadata\MetadataRegistry;

$metadata = MetadataRegistry::fromClasses([Article::class, Ticket::class]);

// A build step can export it...
file_put_contents('entities.php', '<?php return ' . var_export($metadata->toArray(), true) . ';');

// ...and a worker load it without scanning a directory.
$metadata = MetadataRegistry::fromArray(require 'entities.php');
```

`fromClasses()` maps exactly the classes it is given and refuses one that
is not an entity. `toArray()` holds only class names, table and column
names, type names and flags, ordered by class, so the same classes always
produce the same array. `fromArray()` accepts only what `toArray()` writes
for those classes as they are declared now: a missing or extra field, a
wrong type, an unknown class or a mapping the source no longer produces
throws `MappingException`. It reflects the classes it names and nothing
else. Nothing is cached outside the instance.

## Opening a unit of work

```php
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
$orm = OrmFactory::create($db, $metadata);

// Once per request, job or command.
$entities = $orm->open();

try {
    $article = $entities->repository(Article::class)->findOrFail($id);
    $article->publish();
    $entities->flush();
} finally {
    $entities->close();
}
```

`OrmFactory` takes a `MysqlLink` or `PostgresLink` client, never a
transaction (see "Transactions"), and holds no unit-of-work state, so one
factory serves the whole process. `open()` returns a new
`EntityManager`, which belongs to one unit of work:

- **Identity map.** An identity is the entity class and its identifier.
  While the manager holds it, every load of that row returns the same
  object, and a later row writes neither its properties nor its snapshot.
  `contains($entity)` answers whether the manager holds an object:
  managed, awaiting insert or scheduled for deletion.
- **`clear()`** detaches every entity and abandons every unflushed
  insert, change and deletion, with no I/O; the next load of a row builds
  a new object.
- **`close()`** does the same and refuses all later use with
  `ClosedEntityManagerException`. It is idempotent, never flushes and
  leaves the link open. `isClosed()` reports it.
- **Fiber ownership.** A manager works only in the Fiber that opened it
  (the main context counts as one). The manager, its repositories, its
  queries and their terminals refuse any other Fiber with
  `CrossFiberAccessException`, before SQL. `close()` is accepted from any
  Fiber, so whoever owns the unit of work can end it; a terminal suspended
  in its SQL at that moment throws `ClosedEntityManagerException` when it
  resumes instead of returning or loading its result. "Flushing" covers a
  `close()` during `flush()`.

A detached entity stays an ordinary PHP object, and nothing tracks its
changes. Open a separate manager for each concurrent Fiber; managers
never share identities.

## Loading

Every entity query selects all mapped columns. Loading a row:

1. converts every mapped column of every row in the result before any
   entity is allocated, the identifier first. A missing column, a null
   identifier or a value its property does not admit throws
   `MappingException`, and nothing from that result is allocated or
   registered. Extra columns are ignored;
2. returns the object already held for the identifier, untouched;
3. otherwise allocates the entity with
   `ReflectionClass::newInstanceWithoutConstructor()`, writes each
   declared property directly — no constructor, setter, hook or magic
   method runs — and only then registers it, with a snapshot of the
   converted values.

| Property type | Admitted driver value |
|---|---|
| `string` | a string |
| `int` | an int, or its canonical decimal string (no sign but `-`, no leading zero, whitespace, fraction or exponent, within PHP's range) |
| `float` | a finite int, float or numeric string |
| `bool` | a bool, `0`, `1`, `"0"` or `"1"` |
| backed enum | a case, or a backing value admitted under its backing type's rule |
| `?T` | also `null` |

A message names the class, property and column, and describes a value by
its type only.

## Repositories and queries

```php
$articles = $entities->repository(Article::class); // EntityRepository<Article>

$articles->find(42);        // ?Article
$articles->findOrFail(42);  // Article, or EntityNotFoundException
$articles->findBy(['authorId' => 7, 'status' => ArticleStatus::Published]); // list<Article>

$page = $articles->query()  // EntityQuery<Article>
    ->where('status', '=', ArticleStatus::Published)
    ->whereIn('authorId', [7, 8])
    ->orderBy('id', 'DESC')
    ->paginate(perPage: 20, page: 2);
```

`find()` converts its argument like the identifier property and returns
a held identity under exactly that key without SQL. Otherwise it queries
the identifier column, and the row that comes back resolves through the
identity map by the identifier the database returned. A UUID looked up in
different letter case misses the held key and queries; it returns the
object already held only when the column's type or collation matches that
spelling and the database returns the stored identifier.

`findBy()` joins equality predicates on properties. `query()` returns an
`EntityQuery` with:

- `where(string $property, string $operator, mixed $value)` — the
  operators and null handling of `Kinetis\QueryBuilder\Query::where()`:
  a null value compiles `=` to `IS NULL` and `!=`/`<>` to `IS NOT NULL`;
- `whereIn(string $property, array $values)`;
- `orderBy(string $property, string $direction = 'ASC')`, `limit()`,
  `offset()`;
- `get()`, `first()`, `exists()`, `count()`;
- `paginate(int $perPage, int $page = 1)` — a
  `Kinetis\QueryBuilder\Paginator` whose `data` holds managed entities;
  it requires an `orderBy()`;
- `cursorPaginate(int $perPage, ?string $cursor, string $property = 'id')`
  — a `Kinetis\QueryBuilder\CursorPaginator` over the column the property
  maps to, which must be unique and increasing.

A property name resolves to its column and a value converts through the
property's type before either reaches the query builder, so an unknown
property or an inadmissible value throws `MappingException` before SQL.
A backed enum binds as its backing value.

`get()` and `findBy()` buffer every matching row, however many there are.
Page through a result that can grow with `cursorPaginate()`.

`EntityRepository` is final. An application repository wraps it:

```php
use Kinetis\Orm\EntityManager;

final readonly class PublishedArticles
{
    public function __construct(private EntityManager $entities) {}

    /** @return list<Article> */
    public function by(int $authorId): array
    {
        return $this->entities->repository(Article::class)
            ->findBy(['authorId' => $authorId, 'status' => ArticleStatus::Published]);
    }
}
```

## Writing

```php
$ticket = new Ticket('Printer on fire');
$entities->persist($ticket);    // awaiting insert

$article = $entities->repository(Article::class)->findOrFail(42);
$article->publish();            // a change, found against the snapshot

$entities->remove($entities->repository(Article::class)->findOrFail(43)); // scheduled for deletion

$entities->flush();             // one transaction
$ticket->id();                  // the key the database generated
```

For one manager, an object is in one of these states:

| State | `contains()` | What `flush()` writes |
|---|---|---|
| Not held: new, or detached | false | nothing |
| Awaiting insert | true | an INSERT |
| Managed | true | an UPDATE of its changed columns, and its next version when versioned, if any |
| Scheduled for deletion | true | a DELETE |

- **`persist($entity)`** validates an object the manager does not hold —
  new, or detached from this or another manager — and schedules its
  insert. Its class must be an entity in the factory's metadata, every
  mapped property initialized and admitted by the table under "Loading"
  (a non-finite float is not), an assigned identifier not null and not
  held by another object of this manager, and a generated identifier
  null. An assigned identity enters the identity map at once, so `find()`
  returns the object; a generated one enters it when the insert commits.
  `persist()` leaves an entity awaiting insert or managed as it is, and
  cancels the deletion of one scheduled for deletion.
- **`remove($entity)`** schedules a managed entity for deletion. Until the
  DELETE commits it stays managed, keeps its identity and is what loads of
  its row return, and `persist()` cancels the deletion. An entity awaiting
  insert is detached instead and its insert dropped, without SQL. Any
  other object is refused.
- **Changes.** A managed entity has a snapshot: the values it was loaded
  or last flushed with. Each `flush()` compares every mapped property with
  it as a database value — a backed enum as its backing value — so a value
  changed and changed back writes nothing. Loading the row again never
  refreshes the snapshot.
- **Ownership.** A manager sees only its own objects. It refuses a second
  object for an identity it holds and the removal of an object it does not
  hold, and persists an entity detached from another manager as new.

A refusal changes nothing and throws `InvalidEntityStateException`, or
`MappingException` for a property value the mapping does not admit.

## Flushing

`flush()` writes everything the manager has pending in one transaction it
begins on the factory's client:

1. Before the transaction, it reads and validates every entity awaiting
   insert as `persist()` does, and every managed entity, refuses an
   identifier or version that changed and an UPDATE whose version cannot
   advance, and computes each managed entity's changed columns. With
   nothing to write, it returns without a transaction or any I/O.
2. One INSERT per entity awaiting insert, in `persist()` order, of every
   mapped column but a generated identifier.
3. One UPDATE of the changed columns, or one DELETE, per entity, by the
   identifier column and, for a versioned entity, the version column
   (see "Optimistic locking"), ordered by entity class and then
   identifier, so concurrent flushes take row locks in one order.
4. COMMIT.

Every statement runs on that transaction. Nothing is batched, and no
statement uses the key another insert generated.

A DELETE must affect exactly one row, and an UPDATE at most one. An
unversioned UPDATE affecting none is followed by an existence check on
the same transaction: the MySQL family counts changed rows rather than
matched ones, so an UPDATE writing the values its row already holds
reports zero. A versioned UPDATE or DELETE affecting none throws
`OptimisticLockException` without that check. A missing row, more than
one affected row, or a generated key that is null or not an int within
PHP's range throws `InvalidEntityStateException` before COMMIT.

Only a COMMIT that returns changes the manager or its entities. Each
inserted or updated entity is then snapshotted with the values the flush
sent, not whatever its properties hold by then; a generated key is
written into its property and registered as the entity's identity; a
versioned entity's version, as inserted or advanced by its update, is
written into its property; and a deleted entity is detached.

While `flush()` runs, the manager refuses every call but `close()` and
`isClosed()` with `InvalidEntityStateException`, including a call made on
the flushing Fiber by code the flush reaches, such as SQL instrumentation.

### When a flush fails

| Failure | Afterwards | Pending work | Throws |
|---|---|---|---|
| Validation, or `beginTransaction()` | open | kept | that exception |
| Anything before COMMIT, with the rollback returning | open, unless `close()` ran | kept | that exception, unwrapped: `OptimisticLockException`, or a driver `QueryException` or `ConnectionException` as the driver threw it |
| Anything before COMMIT, with the rollback throwing | closed | abandoned | `RollbackFailedException`: `getPrevious()` is the first failure, `$rollbackFailure` the rollback's |
| COMMIT | closed | abandoned | `UnknownFlushOutcomeException`: `getPrevious()` is the COMMIT failure |

A failure before COMMIT sent no COMMIT, so the database kept nothing of
the flush, and the manager and its entities are as they were: every
insert, change and deletion is still pending against its original
snapshot, and no generated key was assigned. Calling `flush()` again
after correcting the cause, such as a unique key conflict, sends the
whole flush again in a new transaction. `flush()` never retries by
itself. After `RollbackFailedException` the manager is closed; the work
can be redone with a new one.

`UnknownFlushOutcomeException` means COMMIT was sent and the call failed:
the database may or may not have applied the flush, and no entity was
changed. Establish what the database holds before doing the work again;
replaying it as if it had failed can apply it twice.

`close()` is accepted while `flush()` runs, from any Fiber. It detaches
everything, then closes the flush's transaction — from another Fiber by
discarding its connection rather than sending ROLLBACK — and the flush
fails by the table above: before COMMIT with the driver's failure or
`ClosedEntityManagerException`, once COMMIT was sent with
`UnknownFlushOutcomeException`. A COMMIT that still returns changes
nothing in the closed manager.

## Optimistic locking

```php
use Kinetis\Orm\Attributes\Entity;
use Kinetis\Orm\Attributes\Version;
use Kinetis\Orm\Exception\OptimisticLockException;

#[Entity(table: 'orders')]
final class Order
{
    #[Version]
    private int $version = 1; // a signed BIGINT NOT NULL column

    public function __construct(private int $id, private string $status) {}

    public function ship(): void
    {
        $this->status = 'shipped';
    }
}

$entities->repository(Order::class)->findOrFail($id)->ship();

try {
    $entities->flush(); // UPDATE ... WHERE id = ? AND version = <loaded>
} catch (OptimisticLockException) {
    // Another writer changed or deleted the row. Nothing of the flush was written.
    $entities->clear();
    $entities->repository(Order::class)->findOrFail($id)->ship(); // decide again on the current row
    $entities->flush();
}
```

- **Mapping.** Only `#[Version]` opts in, on at most one property per
  entity, typed `int` — not nullable, not an enum — and not the
  identifier. `MappingException` refuses anything else when the metadata
  is built.
- **Column.** The version column must hold every version the application
  reaches. Use a signed `BIGINT NOT NULL`. A narrower column, or a server
  that clamps or truncates an out-of-range value instead of refusing it,
  such as MySQL outside strict SQL mode, breaks the contract. Nothing
  inspects the schema.
- **Initial version.** A new entity holds an initialized version before
  `flush()`: conventionally `1`, though any int is accepted so an
  existing sequence can continue. Its INSERT writes that value like any
  other property and does not advance it. The ORM never infers, defaults,
  generates or reads back a version.
- **Owned by the manager.** Once an entity is loaded or flushed, the
  manager controls its version: `flush()` refuses one the application
  changed with `InvalidEntityStateException`, before the transaction
  begins.
- **UPDATE.** A versioned entity without changes writes nothing and keeps
  its version. With changes, `flush()` plans the next version, the
  snapshot's plus one, and sends one UPDATE setting the changed columns
  and the version column to that value, where the identifier column and
  the version column both still hold the snapshot's values. A matching
  row always changes, so the MySQL family's changed-row count reports it.
  An UPDATE at `PHP_INT_MAX` cannot advance and is refused with
  `InvalidEntityStateException` before the transaction begins.
- **DELETE.** One DELETE where the identifier column and the version
  column hold the snapshot's values, at `PHP_INT_MAX` too.
- **Conflict.** A versioned UPDATE or DELETE that affects no row throws
  `OptimisticLockException`: the row was deleted or its version changed,
  which are the same stale write, so no existence check follows. The
  message names the class and statement, never an identifier or version.

A conflict fails the flush before COMMIT, as "When a flush fails" states:
the transaction is rolled back, the exception is rethrown unwrapped, and
the manager stays open with every version, snapshot and pending change as
it was, the rest of the flush included. Calling `flush()` again sends the
same stale statement and conflicts again: the manager never reloads,
merges or retries. To recover, `clear()` the manager, which abandons all
of its pending work, or `close()` it and open another, then load the
entity again, reapply the operation and flush. A rollback that fails
throws `RollbackFailedException`; a COMMIT that fails throws
`UnknownFlushOutcomeException`, leaves the version in memory unchanged
and must not be replayed as if it failed.

An entity is a plain object that code can change while the flush waits
on the database. Once COMMIT returns, its version property is
overwritten with the version the database acknowledged, while a change
to any other property stays pending against the snapshot of the values
sent, and the next `flush()` writes it.

## The query builder underneath

`EntityQuery::builder()` returns a copy of the underlying
`Kinetis\QueryBuilder\Query`, carrying the table, every mapped column and
the predicates added so far, for SQL the entity query does not express:
joins, projections, raw fragments, aggregates, locks. Changing the copy
leaves the entity query unchanged, and its terminals return arrays or
DTOs that no `EntityManager` manages.

## Transactions

Each `flush()` that writes begins exactly one transaction on the
factory's client and ends it before returning. There is no
transaction-bound ORM session: `OrmFactory::create()` refuses a
`MysqlTransaction` or `PostgresTransaction` with
`InvalidArgumentException`, and a manager never joins a transaction.

`flush()` is not supported while the calling Fiber holds a transaction
of its own on that client, as inside a `TransactionGuard::transaction()`
callback: the flush's transaction takes a second connection, and waiting
for a row lock the outer transaction holds blocks the Fiber on itself.
Nothing detects this. ORM reads in such a Fiber are refused by the client
with `Kinetis\Persistence\Exception\TransactionException` rather than run
on a second connection outside the transaction. Work that shares a
transaction with other SQL, and a locking read, use
`new Query($transaction)` from the query builder, which returns arrays or
DTOs rather than managed entities.

## Not in scope

Relationships, cascades, collections, eager or lazy loading, timestamp,
string or database-generated versions, refreshing or merging an entity,
conflict resolution, a transaction-bound ORM session or joining an
existing transaction, locking entity reads, batched or bulk writes,
automatic retries, flushing on `close()` or destruction, timestamps or
`DateTimeImmutable` properties, custom value converters, UUID generation,
composite identifiers, inheritance, partial entities, transient
properties, schema validation, CLI commands, streaming, or static model
methods.

## Installation

```sh
composer require kinetis/orm
```

Requires PHP 8.4+ and the extension for the driver you use (see
[`kinetis/persistence`](https://github.com/kinetis-dev/persistence)). In
a Kinetis application,
[`kinetis/database-bridge`](https://github.com/kinetis-dev/database-bridge)
compiles the entity metadata and binds a request-scoped `EntityManager`.
Full documentation:
[kinetis.dev/docs/orm.html](https://kinetis.dev/docs/orm.html).

## License

MIT — see [LICENSE](LICENSE).
