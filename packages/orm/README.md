<p align="center">
  <img src="logo.svg" alt="Kinetis" width="420">
</p>

<p align="center">
  <strong>kinetis/orm</strong>
  <br>
  <strong>A read-side data mapper for MySQL and PostgreSQL</strong>
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
loaded through typed repositories and entity queries, hydrated without
their constructors, and held in one identity map per unit of work. This
release reads only: it has no writes, relationships or change tracking.

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
  named exactly `id`. There is exactly one, typed `int` or `string`
  (nullable allowed, a loaded null is not). A string identifier covers
  application-generated UUID text stored in a PostgreSQL `uuid` or a
  MySQL/MariaDB `CHAR(36)` column; this package does not generate,
  normalize or validate UUIDs.
- **Types.** `string`, `int`, `float`, `bool`, a backed enum, and the
  nullable form of each.
- **Classes.** An entity has no parent class and is neither abstract nor
  readonly; it may be final. Its constructor's signature and visibility
  do not matter.

`MappingException` refuses, when the metadata is built and before any
SQL: a class without `#[Entity]`, a readonly class or property, a hooked
or virtual property, an untyped property, a union other than a nullable
type, an intersection, any other type (`mixed`, `array`, an object,
`DateTimeImmutable`, a unit enum), a missing or second identifier, an
invalid name and a duplicate column.

## Metadata

```php
use Kinetis\Orm\Metadata\MetadataRegistry;

$metadata = MetadataRegistry::fromClasses([Article::class, Author::class]);

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
} finally {
    $entities->close();
}
```

`OrmFactory` takes a `MysqlLink` or `PostgresLink` client, never a
transaction (see "Transactions"), and holds no unit-of-work state, so one
factory serves the whole process. `open()` returns a new
`EntityManager`, which belongs to one unit of work:

- **Identity map.** An identity is the entity class and the identifier
  converted from the loaded row. While the manager holds it, every load
  of that row returns the same object, and a later row never writes to
  it. `contains($entity)` answers whether the manager holds an object.
- **`clear()`** detaches every entity with no I/O; the next load of a row
  builds a new object.
- **`close()`** detaches every entity and refuses all later use, with
  `ClosedEntityManagerException`. It is idempotent, flushes nothing and
  leaves the link open. `isClosed()` reports it.
- **Fiber ownership.** A manager works only in the Fiber that opened it
  (the main context counts as one). The manager, its repositories, its
  queries and their terminals refuse any other Fiber with
  `CrossFiberAccessException`, before SQL. `close()` is accepted from any
  Fiber, so whoever owns the unit of work can end it; a terminal suspended
  in its SQL at that moment throws `ClosedEntityManagerException` when it
  resumes instead of returning or loading its result.

A detached entity stays an ordinary PHP object. Open a separate manager
for each concurrent Fiber; managers never share identities.

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
   method runs — and only then registers it.

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

## The query builder underneath

`EntityQuery::builder()` returns a copy of the underlying
`Kinetis\QueryBuilder\Query`, carrying the table, every mapped column and
the predicates added so far, for SQL the entity query does not express:
joins, projections, raw fragments, aggregates, locks. Changing the copy
leaves the entity query unchanged, and its terminals return arrays or
DTOs that no `EntityManager` manages.

## Transactions

There is no transaction-bound ORM session. `OrmFactory::create()` refuses
a `MysqlTransaction` or `PostgresTransaction` with
`InvalidArgumentException`, so a manager reads through its factory's
client, never through a transaction. A Fiber holding a
transaction it opened on that client, through `TransactionGuard` or
`beginTransaction()`, has its ORM reads refused by the client with
`Kinetis\Persistence\Exception\TransactionException` rather than run on a
second connection outside the transaction. A locking read, or any read
inside a transaction, uses `new Query($transaction)` from the query
builder and returns arrays or DTOs rather than managed entities.

## Not in scope

No `persist()`, `remove()` or `flush()`, change tracking, generated
identifiers, optimistic locking, timestamps or `DateTimeImmutable`
properties, relationships, eager or lazy loading, partial entities,
custom value converters, composite identifiers, inheritance, transient
properties, cascades, schema validation, CLI commands, streaming, or
static model methods.

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
