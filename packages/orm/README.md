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
deletes of an entity carrying `#[Version]` are optimistically locked. A
transaction session locks entity rows and writes entities and
query-builder SQL in one transaction. An entity references another
through an explicit `#[BelongsTo]` relationship, which an entity query
loads when asked.

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
  nullable form of each; on a `#[BelongsTo]` property, an entity class
  (see "Relationships").
- **Classes.** An entity has no parent class and is neither abstract nor
  readonly; it may be final. Its constructor's signature and visibility
  do not matter.

`MappingException` refuses, when the metadata is built and before any
SQL: a class without `#[Entity]`, a readonly class or property, a hooked
or virtual property, an untyped property, a union other than a nullable
type, an intersection, any other type (`mixed`, `array`, an object
without `#[BelongsTo]`, `DateTimeImmutable`, a unit enum), a missing or
second identifier, a generated identifier not typed `?int`, a second
`#[Version]`, a version property that is the identifier or is not typed
`int`, an invalid name, a duplicate column, and each relationship
refusal under "Relationships".

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
is not an entity, or a relationship whose target is not one of them.
`toArray()` holds only class names, table and column names, type names
and flags, ordered by class, so the same classes always produce the same
array. `fromArray()` accepts only what `toArray()` writes
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
transaction (see "Transaction sessions"), and holds no unit-of-work
state, so one factory serves the whole process. `open()` returns a new
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
   declared property but a relationship directly — no constructor,
   setter, hook or magic method runs — and only then registers it, with a
   snapshot of the converted values, a relationship's foreign key
   included.

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
- `lockForUpdate(LockWait $wait = LockWait::Wait)` and `lockForShare()`,
  inside a transaction session only (see "Transaction sessions");
- `with(string ...$relations)`, the relationships `get()`, `first()`,
  `paginate()` and `cursorPaginate()` load (see "Relationships");
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

## Relationships

```php
use Kinetis\Orm\Attributes\BelongsTo;
use Kinetis\Orm\Attributes\Entity;

#[Entity(table: 'authors')]
final class Author
{
    public int $id;

    public string $name;

    #[BelongsTo]
    public ?Organization $organization; // organization_id, nullable
}

#[Entity(table: 'posts')]
final class Post
{
    public int $id;

    public string $title;

    #[BelongsTo(column: 'written_by')]
    public Author $author;
}

$posts = $entities->repository(Post::class)
    ->query()
    ->where('author', '=', 7) // written_by = 7
    ->with('author.organization')
    ->get();

$posts[0]->author->organization?->name;
```

`Organization` is an application entity with a `name` property.

- **Mapping.** `#[BelongsTo]` marks a property typed with one entity
  class — nullable or not, `self` included — mapped in the same
  `MetadataRegistry`. The property maps one foreign-key column: the
  property name in snake case followed by `_id`, unless `column` names
  it, under the rules of any column name. The column holds the target's
  identifier, so the relationship's type in the metadata is that
  identifier's type. Only the side holding the foreign key is mapped.
- **Refusals.** `MappingException` refuses, when the metadata is built, a
  type that is not a class, `#[Column]`, `#[Id]` or `#[Version]` on the
  same property, a default value, and a target the registry does not map.
- **Loaded or not.** Loading a row leaves a relationship uninitialized,
  and reading it throws PHP's `Error` as for any uninitialized typed
  property. Only `with()` or the application initializes it: there is no
  proxy, no lazy loading and no loaded-state API. The manager's snapshot
  holds the foreign key either way.

### Loading relationships

`with(string ...$relations)` names relationship paths: `author`, and
`author.organization` through the target's own relationship. Repeated
calls add to one set. An empty segment, an unknown property or a property
that is not a relationship throws `MappingException` before SQL.

`get()`, `first()`, `paginate()` and `cursorPaginate()` load the paths
after their own statements. `find()`, `findBy()`, `count()` and
`exists()` load nothing and send only their usual statements, so
`with(...)->count()` counts what `with(...)->get()` returns. Each
relationship loads one level at a time:

1. The distinct non-null foreign keys of the level's entities whose
   relationship is uninitialized are read from their snapshots.
2. One `SELECT` of the target's mapped columns where its identifier
   column is `IN` those keys, for at most 1,000 keys per statement, on
   the manager's link. There is no join. Every key is selected, the key
   of a target the manager already holds included, and a level without
   keys sends nothing.
3. Every row loads under "Loading", so a held target is returned as it
   is. A null key sets a nullable relationship to null; a key no row
   matches throws `MappingException`, naming the class, property and
   column.
4. The targets are the next level's entities.

A relationship the application already initialized is never overwritten
or compared with its foreign key. Its target joins the next level and
must be an entity this manager manages: otherwise
`InvalidEntityStateException` is thrown before that level's statements.

Every statement of the load runs inside its terminal, and the manager is
checked after each one before anything is assigned, so a `close()`
meanwhile throws `ClosedEntityManagerException`. A failure keeps what
earlier statements assigned. In a transaction session every statement
runs on the session's transaction, and a failure fails the session as any
terminal failure does. `lockForUpdate()` and `lockForShare()` lock the
root statement only: a relationship's statements carry no lock clause.

### Relationship predicates

A relationship property is also its foreign key: `where()`, `whereIn()`,
`orderBy()`, `findBy()` and a `cursorPaginate()` property compile to its
column, and a value converts like the target's identifier, so `'7'`
matches an `int` identifier and an entity object throws
`MappingException`. No predicate reaches a target's own properties; a
join belongs to `builder()`.

### Writing relationships

- **Targets.** A relationship holds null, where its type allows it, or an
  entity this manager manages: loaded, or inserted by a flush whose
  COMMIT returned. The foreign key is the identifier in the manager's
  snapshot of that entity. `persist()`, and `flush()` for every entity it
  writes before its transaction begins, refuse anything else with
  `InvalidEntityStateException`: an entity awaiting insert, one the same
  flush would insert included, a detached entity, and one another manager
  holds. A new entity needs every relationship initialized.
- **Never loaded.** A managed entity whose relationship is uninitialized
  writes the foreign key its snapshot holds, so changing another property
  never writes that column.
- **Reassignment.** Assigning another managed target, or null, changes
  the foreign key: the UPDATE sets the column, under the version predicate
  of a versioned entity, and the snapshot takes the new key once COMMIT
  returns.
- **Removal.** Nothing cascades. Removing an entity another row still
  references sends its DELETE, and the database's foreign key decides: a
  refusal fails the flush before COMMIT (see "When a flush fails"). No
  object holding the removed entity changes.

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
  (a non-finite float is not), every relationship's target one this
  manager manages (see "Writing relationships"), an assigned identifier
  not null and not held by another object of this manager, and a
  generated identifier null. An assigned identity enters the identity map at once, so `find()`
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
  it as a database value — a backed enum as its backing value, a
  relationship as its foreign key — so a value changed and changed back
  writes nothing. Loading the row again never
  refreshes the snapshot.
- **Ownership.** A manager sees only its own objects. It refuses a second
  object for an identity it holds and the removal of an object it does not
  hold, and persists an entity detached from another manager as new.

A refusal changes nothing and throws `InvalidEntityStateException`, or
`MappingException` for a property value the mapping does not admit.

## Flushing

`flush()` writes everything the manager has pending in one transaction.
A manager from `open()` begins it on the factory's client; inside a
transaction session, `flush()` writes on the session's transaction and
leaves COMMIT to the factory (see "Transaction sessions"):

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

This table covers a manager from `open()`. A flush inside a transaction
session fails the session instead: see "When a session fails".

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
joins, projections, raw fragments, aggregates. Changing the copy leaves
the entity query unchanged, and its terminals return arrays or DTOs that
no `EntityManager` manages.

`EntityManager::builder()` returns a fresh `Query` on the manager's link:
the factory's client for a manager from `open()`, the session's
transaction inside `OrmFactory::transaction()`. Its results are unmanaged
too.

## Transaction sessions

```php
use Kinetis\Orm\EntityManager;

$shipped = $orm->transaction(function (EntityManager $entities) use ($id): bool {
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
```

`Order` is the entity under "Optimistic locking", and `order_events` an
application table. `OrmFactory::transaction(callable $callback): mixed`
begins one transaction on the factory's client, passes the callback an
`EntityManager` bound to it, commits once the callback returns, and
returns the callback's result.

- **One transaction.** Every read, identity-map miss, locking read and
  flush statement of the bound manager, and every statement of its
  `builder()`, runs on that transaction; the client runs none of them.
  The transaction and its connection stay pinned for as long as the
  callback runs, whatever else the callback waits on.
- **Explicit flush.** Nothing is flushed for you. A callback that returns
  without `flush()` commits no ORM change, as closing a manager from
  `open()` abandons unflushed work. A `flush()` with nothing to write
  sends nothing and changes nothing.
- **One writing flush, last.** A `flush()` that writes sends every
  statement "Flushing" lists but COMMIT. It then seals the manager: until
  the callback returns, the manager, its repositories, its entity queries
  and their terminals refuse every call but `close()` and `isClosed()`
  with `InvalidEntityStateException`, a second flush and `builder()`
  included, so that flush is the callback's final ORM operation. A `Query`
  the callback already holds from a `builder()` is the caller's own and
  still runs on the transaction.
- **State after COMMIT.** Only once COMMIT returns are the flushed work's
  generated keys, versions and snapshots applied and its deleted entities
  detached, as "Flushing" describes. Then, and on every failure, the
  manager is closed and every entity detached: an entity the callback
  returns is a plain detached object.
- **Locking reads.** `EntityQuery::lockForUpdate(LockWait $wait = LockWait::Wait)`
  and `lockForShare()` lock the rows the query reads until the session's
  transaction ends. `Kinetis\QueryBuilder\Query` decides which terminals,
  clauses and wait modes combine with a lock, and each dialect's SQL. A
  manager from `open()` refuses both with `InvalidEntityStateException`
  before SQL. `find()` answers an identity the manager holds without SQL,
  so it takes no lock: lock through `query()`. The relationships the query
  loads are read on the transaction without a lock (see "Loading
  relationships").
- **Raw SQL.** A raw write is not reconciled with the manager: it changes
  no snapshot or version the manager holds, so the flush can overwrite it
  or conflict with it, and keeping the two consistent is the caller's
  responsibility.
- **ORM failures.** A failure an entity query terminal throws — including
  a refusal the query builder raises before sending SQL — or `flush()`
  throws fails the session. If the callback catches it, every later ORM
  call is refused with `InvalidEntityStateException`, whose `getPrevious()`
  is that failure, and the session rolls back instead of committing and
  rethrows that failure as primary (see "When a session fails"). A
  `MappingException` from `where()`, `whereIn()`, `orderBy()`, `with()`, a
  `find()` identifier or a cursor property is thrown before the terminal
  reaches the query builder and does not fail the session.
- **Raw failures.** The manager cannot see a raw `Query` fail. When the
  callback catches one, COMMIT decides: MySQL and MariaDB keep the
  transaction open after an ordinary statement error and commit the rest
  of its work, while PostgreSQL aborts the transaction, and a lost
  connection, a deadlock or a MySQL lock-wait timeout end it. An ended or
  aborted transaction fails COMMIT with `CommitNotAcknowledgedException`.
- **No nesting.** Calling `transaction()` on a factory from a Fiber already
  inside one of its sessions throws `InvalidEntityStateException` before
  anything begins. Concurrent Fibers each run their own session on one
  factory, as far as the client serves concurrent transactions; separate
  factory objects do not see each other's sessions, so keep one factory
  per link. A session never joins a transaction the application began:
  `OrmFactory::create()` refuses a `MysqlTransaction` or
  `PostgresTransaction` with `InvalidArgumentException`.
- **Fiber ownership.** The bound manager works only in the Fiber that
  called `transaction()`. `close()` is accepted from any Fiber and ends
  the session's transaction — from that Fiber by rolling it back, from
  another by discarding its connection — so nothing of it commits.

### When a session fails

| Way out | COMMIT | Entity state | Throws |
|---|---|---|---|
| `beginTransaction()` fails | not sent | untouched; no manager exists | that exception |
| A nested call | not sent | the running session is unaffected | `InvalidEntityStateException` |
| An ORM failure was recorded, whether the callback then returned or threw, and the rollback returns | not sent | not applied | that first ORM failure |
| The callback throws with no ORM failure recorded, and the rollback returns | not sent | not applied | the callback's exception |
| Either of the two above, with the rollback throwing | not sent | not applied | `RollbackFailedException`: `getPrevious()` is the primary failure, `$rollbackFailure` the rollback's |
| The callback returns with the manager closed and no ORM failure recorded | not sent | not applied | `ClosedEntityManagerException` |
| COMMIT throws | unacknowledged | not applied | `CommitNotAcknowledgedException`: `getPrevious()` is the driver failure |
| COMMIT returns | acknowledged | applied | nothing: the callback's result is returned |

The primary failure is the first failure an entity query terminal or
`flush()` threw, once one is recorded, even when the callback caught it
and then threw an exception of its own; only a callback that throws with
no ORM failure recorded makes its own exception primary. A rollback that
returns rethrows the primary failure, and a rollback that throws makes it
`RollbackFailedException::getPrevious()`.

In every row but the first two the manager is closed and its entities
detached. `CommitNotAcknowledgedException` means the ORM received no
acknowledged COMMIT. The database may already have rolled the transaction
back before COMMIT — PostgreSQL does for a transaction a failed statement
aborted — or a COMMIT that was sent may have an unknown outcome. Do not
assume the work failed and do not replay it; establish what the database
holds first. A `flush()` on a manager from `open()` keeps its own contract
and `UnknownFlushOutcomeException`.

A Fiber destroyed while suspended inside the callback sends neither
COMMIT nor ROLLBACK: the transaction is dropped, its connection discarded
so the server rolls the work back, and `kinetis/persistence` records the
outcome as unknown.

A manager from `open()` never joins a transaction. Its `flush()` is not
supported while the calling Fiber holds a transaction on the same client,
as inside a `TransactionGuard::transaction()` or `OrmFactory::transaction()`
callback: the flush's transaction needs a second connection, and waiting
for a row lock the first transaction holds blocks the Fiber on itself.
Its reads are refused there with
`Kinetis\Persistence\Exception\TransactionException` rather than run
outside the transaction — by a native client for the Fiber holding the
transaction, by a PDO client for every Fiber. Use the session's manager
instead.

## Not in scope

Relationships other than `#[BelongsTo]` (one-to-one or one-to-many from
the referenced side, many-to-many, inverse sides), cascades, orphan
removal, collections, lazy loading or proxies, joined eager loading,
predicates on a target's properties, timestamp,
string or database-generated versions, refreshing or merging an entity,
conflict resolution, joining a transaction the application began, nested
sessions or savepoints, more than one writing flush per session,
provisional identifiers or versions, batched or bulk writes,
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
