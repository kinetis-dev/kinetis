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
through an explicit `#[BelongsTo]` relationship, and reaches the entities
referencing it through explicit `#[HasOne]` and `#[HasMany]` inverse
relationships; an entity query loads either side when asked. An inverse
relationship marked `owned` is an aggregate: one `persist()` writes the
whole graph below it in foreign-key order, one `remove()` deletes it, and
a child dropped from a relationship the manager loaded is deleted or
moved. A `#[ManyToMany]` relationship maps a join table, whose rows one
side owns and the other only reads.

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
- **Connection.** `#[Entity(connection: 'reporting')]` names the database
  the entity lives on; without it, the entity lives on `default`. A
  connection name is lowercase ASCII letters and digits, starting with a
  letter; `app` is reserved, because a host deriving `DB_<NAME>_*` keys
  would read its `DB_NAME` from `DB_APP_NAME`, the default connection's
  application-name key. See "Connections".
- **Columns.** Every non-static property is mapped, trait properties
  included, and every one but an inverse relationship maps a column (see
  "Relationships"). A column is the property name in snake case
  (`publishedAt` maps to `published_at`) unless `#[Column(name: ...)]`
  names it. A column
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
- **Types.** `string`, `int`, `float`, `bool`, a backed enum,
  `DateTimeImmutable` (see "Timestamps"), `Kinetis\Orm\Date` (see
  "Dates"), and the nullable form of each;
  on a `#[BelongsTo]` or `#[HasOne]` property, an
  entity class, and on a `#[HasMany]` or `#[ManyToMany]` property, `array`
  (see "Relationships" and "Many-to-many relationships").
- **Classes.** An entity has no parent class and is neither abstract nor
  readonly; it may be final. Its constructor's signature and visibility
  do not matter.

`MappingException` refuses, when the metadata is built and before any
SQL: a class without `#[Entity]`, a readonly class or property, a hooked
or virtual property, an untyped property, a union other than a nullable
type, an intersection, any other type (`mixed`, `array` without
`#[HasMany]` or `#[ManyToMany]`, an object without `#[BelongsTo]` or
`#[HasOne]`, such as `DateTime`, `DateTimeInterface` or a subclass of
`DateTimeImmutable`, a unit enum), a missing or
second identifier, a generated identifier not typed `?int`, a second
`#[Version]`, a version property that is the identifier or is not typed
`int`, an invalid name or connection name, the reserved connection name
`app`, a duplicate column, and each relationship refusal under
"Relationships".

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
array. Each entity lists its column-mapped `properties` and, apart from
them, its inverse relationships as `inverses` and its `#[ManyToMany]`
relationships as `joins`, each carrying the join table and the two
columns that end of it reads, and its `connection`. `connections()` lists
every connection an entity names, each once and in byte order, and
`connectionFor($class)` returns one entity's. `fromArray()` accepts only what
`toArray()` writes for those classes as they are declared now: a missing or extra field, a
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
state, so one factory serves the whole process. It maps the entities of
one connection, `default` unless its third argument names another (see
"Connections"). `open()` returns a new
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

## Connections

An application whose entities live on more than one database keeps one
`MetadataRegistry` for all of them and one client per connection:

```php
use Kinetis\Orm\EntityManagerRegistry;
use Kinetis\Orm\OrmFactoryRegistry;

#[Entity(table: 'orders', connection: 'reporting')]
final class Order
{
    // ...
}

// Once per process.
$factories = OrmFactoryRegistry::create(['default' => $db, 'reporting' => $reportingDb], $metadata);

// Once per request, job or command.
$managers = EntityManagerRegistry::create($factories);

try {
    $order = $managers->managerFor(Order::class)->repository(Order::class)->findOrFail($id);
    $article = $managers->manager('default')->repository(Article::class)->findOrFail($articleId);
} finally {
    $managers->close();
}
```

`$reportingDb` is the reporting database's client, built as `$db` is
above.

- **One factory per connection.** `OrmFactory::create($link, $metadata,
  'reporting')` maps the entities on `reporting` over `$link`, which is
  that connection's client; a connection no entity names gives a factory
  that maps none. Every other entity is refused by its managers as it is
  by any factory's: `MappingException` from `repository()`,
  `InvalidEntityStateException` from `persist()`.
- **`OrmFactoryRegistry`** is request-neutral, like the factories it
  holds. `create()` builds one factory per link, keyed by connection, and
  throws `InvalidArgumentException` when a connection an entity names has
  no link. `factory($connection)` and `factoryFor($class)` return one;
  a connection without a link throws `InvalidArgumentException`, a class
  outside the metadata `MappingException`.
- **`EntityManagerRegistry`** belongs to one unit of work and to the
  Fiber that created it. `manager($connection)` and `managerFor($class)`
  open that connection's manager on first use and return the same one
  after, so a unit of work holds at most one manager per connection.
  Another Fiber is refused with `CrossFiberAccessException`. `close()`
  closes every manager it opened, never flushes, leaves the links open
  and is idempotent; any Fiber may call it, and later `manager()` and
  `managerFor()` calls throw `ClosedEntityManagerException`. Create one
  per unit of work: two registries share no manager and no identity.
- **Nothing spans two connections.** Each manager holds one link, one
  identity map and one unit of work: `flush()` writes one transaction on
  its own connection, and `transaction()` belongs to one factory. Write to
  two databases as two units of work, each flushed or committed on its
  own; the second can fail after the first committed.

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
   column-mapped property but a `#[BelongsTo]` directly — no constructor,
   setter, hook or magic method runs — and only then registers it, with a
   snapshot of the converted values, a `#[BelongsTo]`'s foreign key
   included. Every relationship, of either side, stays uninitialized.

| Property type | Admitted driver value |
|---|---|
| `string` | a string |
| `int` | an int, or its canonical decimal string (no sign but `-`, no leading zero, whitespace, fraction or exponent, within PHP's range) |
| `float` | a finite int, float or numeric string |
| `bool` | a bool, `0`, `1`, `"0"` or `"1"` |
| backed enum | a case, or a backing value admitted under its backing type's rule |
| `DateTimeImmutable` | a `DateTimeImmutable`, or a UTC string `YYYY-MM-DD HH:MM:SS` with an optional `.` and one to six fraction digits, loaded in UTC (see "Timestamps") |
| `Date` | a `Date`, or a string `YYYY-MM-DD` naming a day that exists, loaded as a `Date` (see "Dates") |
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
  maps to, which must be unique and increasing; a timestamp or date
  cursor is admitted as "Timestamps" or "Dates" describes.

A property name resolves to its column and a value converts through the
property's type before either reaches the query builder, so an unknown
property or an inadmissible value throws `MappingException` before SQL.
A backed enum binds as its backing value. An inverse relationship maps
no column, so naming one there throws `MappingException` before SQL too
(see "Relationship predicates").

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
use Kinetis\Orm\Attributes\HasMany;
use Kinetis\Orm\Attributes\HasOne;

#[Entity(table: 'authors')]
final class Author
{
    public int $id;

    public string $name;

    #[BelongsTo]
    public ?Organization $organization; // organization_id, nullable

    #[HasOne(mappedBy: 'author', owned: true)]
    public ?Profile $profile;

    /** @var list<Post> */
    #[HasMany(target: Post::class, mappedBy: 'author')]
    public array $posts;
}

#[Entity(table: 'posts')]
final class Post
{
    public int $id;

    public string $title;

    #[BelongsTo(column: 'written_by')]
    public Author $author;

    /** @var list<Comment> */
    #[HasMany(target: Comment::class, mappedBy: 'post', owned: true)]
    public array $comments;
}

#[Entity(table: 'comments')]
final class Comment
{
    public int $id;

    #[BelongsTo]
    public Post $post; // post_id

    #[BelongsTo]
    public Author $author; // author_id

    public string $body;
}

#[Entity(table: 'profiles')]
final class Profile
{
    public int $id;

    #[BelongsTo]
    public Author $author; // author_id, with a unique constraint

    public string $bio;
}

$posts = $entities->repository(Post::class)
    ->query()
    ->where('author', '=', 7) // written_by = 7
    ->with('author.organization', 'comments.author')
    ->get();

$posts[0]->author->organization?->name;
$posts[0]->comments[0]->author->name;

$authors = $entities->repository(Author::class)
    ->query()
    ->with('profile', 'posts')
    ->cursorPaginate(25, $cursor);

$authors->data[0]->profile?->bio;
```

`Organization` is an application entity with a `name` property, and
`$cursor` the cursor of the page the client asked for.

- **Owning side.** `#[BelongsTo]` marks a property typed with one entity
  class — nullable or not, `self` included — mapped in the same
  `MetadataRegistry`. The property maps one foreign-key column: the
  property name in snake case followed by `_id`, unless `column` names
  it, under the rules of any column name. The column holds the target's
  identifier, so the relationship's type in the metadata is that
  identifier's type. It is the only side that maps or writes a foreign
  key.
- **Inverse side.** `#[HasOne(mappedBy: ...)]` and
  `#[HasMany(target: ..., mappedBy: ...)]` mark a property holding the
  entities whose `#[BelongsTo]` property `mappedBy` references this
  entity. `mappedBy` names a `#[BelongsTo]` property of the target whose
  target is exactly the declaring class, and that property's column is
  the one the relationship reads; there is no join-column option. A
  `#[HasOne]` property is typed with one entity class, its target —
  nullable or not, `self` included. A `#[HasMany]` property is typed
  exactly `array`, not nullable, and `target` names its element class,
  `self::class` included; PHP cannot declare an element type, so a
  `list<Target>` PHPDoc documents it. The target is mapped in the same
  `MetadataRegistry`. An inverse relationship maps no column of its own
  table.
- **Join table.** `#[ManyToMany]` marks an array property holding the
  entities a join table links this one to, and is the third kind of
  relationship: no side of it maps a column of its own table.
  "Many-to-many relationships" below is its contract.
- **Ownership.** `owned: true` on `#[HasOne]` or `#[HasMany]` makes the
  relationship an aggregate edge, which "Aggregates" below states in
  full: `flush()` discovers new targets through it, `remove()` removes
  them, and a target dropped from a relationship this manager loaded is
  deleted or moved. It defaults to `false`, and a relationship that is
  not owned reads and writes exactly as before. Ownership is a decision
  about lifecycle, not about the foreign key: a `Comment` lives and dies
  with its `Post`, while a `Post` is written and removed on its own.
  Each entity class has at most one owned inverse relationship in a
  `MetadataRegistry`, and one `#[BelongsTo]` property is named by at most
  one of them, so one mapping alone decides how a row is discovered and
  removed.
- **One connection.** Both ends of every relationship, of any kind,
  live on the same connection. A relationship between entities on two
  connections is refused when the metadata is built, naming both classes
  and both connections.
- **Refusals.** `MappingException` refuses, when the metadata is built,
  for `#[BelongsTo]`: a type that is not a class, `#[Column]`, `#[Id]` or
  `#[Version]` on the same property, a default value, and a target the
  registry does not map. For `#[HasOne]` and `#[HasMany]`: a `#[HasOne]`
  type that is not a class, a `#[HasMany]` type other than `array`,
  `#[Column]`, `#[Id]`, `#[Version]`, `#[BelongsTo]` or both inverse
  attributes on the same property, a default value, a target the registry
  does not map, and a `mappedBy` naming no `#[BelongsTo]` property of the
  target or one that references another class, a second owned inverse
  relationship to one entity class, and two owned inverse relationships
  over one `#[BelongsTo]` property. A default value is refused on every
  kind because an initialized relationship is never loaded.
  "Many-to-many relationships" lists `#[ManyToMany]`'s own refusals.
- **Loaded or not.** Loading a row leaves every relationship, of either
  side and a `#[ManyToMany]` included, uninitialized, and reading one
  throws PHP's `Error` as for any uninitialized typed property. Only
  `with()` or the application
  initializes it: there is no proxy, no lazy loading and no loaded-state
  API. The manager's snapshot holds a `#[BelongsTo]`'s foreign key either
  way, and nothing of any other relationship.

### Loading relationships

`with(string ...$relations)` names relationship paths: each segment is a
`#[BelongsTo]`, `#[HasOne]`, `#[HasMany]` or `#[ManyToMany]` property of
the entity the segment before it loads, as in `author.organization`,
`comments.author` or `author.posts`. Repeated calls add to one set. An empty segment, an
unknown property or a property that is not a relationship throws
`MappingException` before SQL.

`get()`, `first()`, `paginate()` and `cursorPaginate()` load the paths
after their own statements. `find()`, `findBy()`, `count()` and
`exists()` load nothing and send only their usual statements, so
`with(...)->count()` counts what `with(...)->get()` returns. Each
relationship loads one level at a time. A `#[BelongsTo]`:

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

An inverse relationship:

1. The distinct identifiers of the level's entities whose relationship is
   uninitialized are read from their snapshots.
2. One `SELECT` of the target's mapped columns where the column of its
   `mappedBy` property is `IN` those identifiers, for at most 1,000
   identifiers per statement, ordered by the target's identifier column
   ascending, on the manager's link. There is no join, and a level
   without identifiers sends nothing.
3. Every row loads under "Loading", so a held target is returned as it
   is, and joins the entity its foreign-key value in that row names — not
   the one a held target's snapshot or its unflushed `#[BelongsTo]`
   property names. A target scheduled for removal still loads until its
   DELETE commits.
4. A `#[HasMany]` is set to the list of its rows' targets, in that order,
   and to an empty list without rows. A `#[HasOne]` is set to the target
   of its one row. Without a row it is set to null when nullable and
   otherwise throws `MappingException`; more than one row throws
   `MappingException`. Both messages name the class, property, target and
   column.
5. The targets are the next level's entities.

A `#[ManyToMany]` relationship reads its join table first, as
"Many-to-many relationships" states.

A relationship the application already initialized, on either side, is
never overwritten by a load, and no load compares it with the database.
A flush reconciles an *owned* relationship, and only one this manager
loaded itself (see "Aggregates"). What an initialized relationship holds
joins the next level and must be an entity of its target class that this
manager manages — for a `#[HasMany]`, every value of the array, whatever its
keys — or null where the type allows it. Anything else throws
`InvalidEntityStateException` before that level's statements, on the last
segment of a path too.

Every statement of the load runs inside its terminal, and the manager is
checked after each one before anything is assigned, so a `close()`
meanwhile throws `ClosedEntityManagerException`. A failure keeps what
earlier statements assigned. In a transaction session every statement
runs on the session's transaction, and a failure fails the session as any
terminal failure does. `lockForUpdate()` and `lockForShare()` lock the
root statement only: a relationship's statements carry no lock clause.

#### Cardinality and buffering

The database is the authority on cardinality. Give the foreign-key column
a `#[HasOne]` reads a unique constraint: the ORM refuses duplicate rows
rather than choosing one, and never inspects the schema.

A `#[HasMany]` loads every matching row into one array, buffered in full
like `get()` and `findBy()`. A root `cursorPaginate()` bounds how many
entities a page holds, not how many targets each of them loads. Do not
eager-load a collection that can grow without bound; page through the
target's repository by its `#[BelongsTo]` property instead:

```php
$comments = $entities->repository(Comment::class)
    ->query()
    ->where('post', '=', $postId) // post_id = $postId
    ->cursorPaginate(50, $cursor);
```

### Relationship predicates

A `#[BelongsTo]` property is also its foreign key: `where()`, `whereIn()`,
`orderBy()`, `findBy()` and a `cursorPaginate()` property compile to its
column, and a value converts like the target's identifier, so `'7'`
matches an `int` identifier and an entity object throws
`MappingException`. No predicate reaches a target's own properties; a
join belongs to `builder()`.

An inverse or `#[ManyToMany]` relationship maps no column of its own
table: `where()`, `whereIn()`, `orderBy()`, `findBy()` and a
`cursorPaginate()` property naming one throw
`MappingException` before SQL. Filter, order and page the targets of an
inverse relationship through their own repository by their `#[BelongsTo]`
property, as above, and those of a `#[ManyToMany]` through its join table
with `builder()`.

### Writing relationships

- **Owning side only.** Only a `#[BelongsTo]` property writes a foreign
  key of an entity table, and only an owning `#[ManyToMany]` property
  writes a join table (see "Many-to-many relationships"). Assigning
  `$post->comments` or `$author->profile` never writes an owner column
  and never advances an owner's version, while an owning `#[ManyToMany]`
  collection advances a versioned owner's. To move a comment
  to another post, set its `post` property to that post. What an *owned*
  relationship adds — which rows a flush discovers, deletes or moves — is
  under "Aggregates"; a relationship that is not owned writes nothing,
  cascades nothing and persists nothing, and a new entity may leave one
  uninitialized.
- **No fixup.** Nothing reconciles the two sides in memory. After a
  flush, a `comments` array already loaded or assigned still holds what
  it held, and a later `with()` keeps it because it is initialized.
  `clear()` the manager, or open another, to load the committed rows.
- **Targets.** A relationship holds null, where its type allows it, or an
  entity this manager holds: managed, or awaiting insert in the same
  flush. A managed target's foreign key is the identifier in the
  manager's snapshot; a target awaiting insert is written after its own
  row exists, with the key that INSERT produced (see "Aggregates"). A new
  entity needs every `#[BelongsTo]` initialized. `persist()` admits any
  object of an entity class in the factory's metadata, and `flush()`
  validates the whole graph before its transaction begins: a detached
  entity, one another manager holds, and anything that is not an entity
  of the target class are refused there with
  `InvalidEntityStateException`.
- **Never loaded.** A managed entity whose relationship is uninitialized
  writes the foreign key its snapshot holds, so changing another property
  never writes that column.
- **Reassignment.** Assigning another target, or null, changes the
  foreign key: the UPDATE sets the column, under the version predicate of
  a versioned entity and advancing its version once, and the snapshot
  takes the new key once COMMIT returns.
- **Removal.** Nothing cascades through a relationship that is not owned.
  Removing an entity another row still references sends its DELETE, and
  the database's foreign key decides: a refusal fails the flush before
  COMMIT (see "When a flush fails"). No object holding the removed entity
  changes.

## Aggregates

An owned relationship makes one entity and the rows below it a single
unit: one `persist()` writes the whole graph, in an order its foreign
keys can take, inside one transaction.

```php
$author = new Author();
[$author->id, $author->name, $author->organization] = [42, 'Jane', null];

$profile = new Profile();
[$profile->id, $profile->author, $profile->bio] = [7, $author, 'Mathematician'];

$author->profile = $profile;    // the owned relationship

$entities->persist($author);    // the profile needs no persist() of its own
$entities->flush();             // INSERT the author, then the profile

$profile->author;               // still $author; nothing in the graph was rewritten
```

`Author::$profile` is `#[HasOne(mappedBy: 'author', owned: true)]`, so
`flush()` finds the profile through it and writes the author's row
first, because the profile's `author_id` names it. The same holds at any
depth and for a `#[HasMany]`: a post's owned `comments` are written
after the post, and their own owned relationships after them. Where the
parent's identifier is generated, the key its INSERT produced is what the
child's foreign key is written with, inside the same transaction, and it
reaches the parent's own property only once COMMIT returns.

Entities persisted separately need no ordering either. Each `persist()`
schedules one entity, and `flush()` orders every row it holds at once:

```php
$entities->persist($comment); // $comment->post is not written yet
$entities->persist($post);
$entities->flush();           // INSERT the post, then the comment
```

### What a flush discovers

One pass runs at the start of `flush()`, before any statement, from
every entity awaiting insert in `persist()` order and then every managed
entity, across their **initialized owned relationships**, depth first in
mapped declaration order and array order. It is iterative and cycle-safe,
and it reads nothing from the database.

- A new object it reaches is validated exactly as `persist()` validates
  one, and scheduled for insert. That covers a child attached after its
  root was persisted.
- An uninitialized relationship is skipped and never loaded. An
  uninitialized property holds no object, so no in-memory entity is lost.
- A relationship that is not owned is not traversed. A `#[BelongsTo]`
  target that is neither managed nor awaiting insert is refused.
- Every object must be an instance of the mapped target class, appear
  once in a relationship, and appear under one owner.
- An object whose deletion this manager committed is refused rather than
  inserted again, so an unchanged PHP array cannot resurrect a deleted
  row. `persist()` on that object still inserts it as a new row.
- The pass is atomic: a refusal anywhere leaves the unit of work exactly
  as it was, with nothing scheduled and no transaction begun.

### Loaded relationships, and what a flush may change through them

The ORM never guesses what the database holds through a relationship.
An owned relationship has a membership to reconcile against only when
**this manager loaded it** with `with()`, or when its owner was inserted
by a flush whose COMMIT returned.

For a relationship the manager loaded, `flush()` compares the membership
it loaded with the one the property holds now. Every decision reads the
child's *foreign key* — its `#[BelongsTo]` property, or, where that
property was never loaded, the value in its snapshot:

| The child | Its final foreign key | What the flush does |
|---|---|---|
| is in the relationship | names this owner | nothing; its own changes write as usual |
| is in the relationship | names another owner | `InvalidEntityStateException` before SQL |
| was dropped from it | still names this owner, or is null | DELETE, with the aggregate below it |
| was dropped from it | names another owner | the ordinary UPDATE moves the row |

Dropping an eagerly loaded child from a collection is a complete
instruction on its own: its `#[BelongsTo]` property may stay
uninitialized, and a non-nullable one never has to be given an
impossible null. Moving a child to another owner means assigning that
owner to the child's `#[BelongsTo]` property; if the new owner's owned
relationship is loaded too, it must hold the child, since the ORM does
not repair the object graph.

```php
$post = $entities->repository(Post::class)->query()->with('comments')->first();

$post->comments = array_values(array_filter($post->comments, fn (Comment $c) => !$c->isSpam()));

$entities->flush(); // DELETE every comment the array no longer holds
```

For an owned relationship the manager did **not** load, `flush()` still
discovers and inserts new children through it, and changes nothing else:
what the database holds behind it is unknown, so no removal, replacement
or orphan is inferred. Load it with `with()`, or operate on the child
entity itself.

Adding or removing children writes no owner column and does not advance
the owner's version. A relationship that is merely reordered sends no
SQL at all.

### Removing an aggregate

`remove($owner)` schedules the owner and everything below it through its
owned relationships, and detaches every entity awaiting insert it
reaches without SQL. `persist()` on a removed owner cancels the removal
for the same subgraph.

```php
$post = $entities->repository(Post::class)->query()->with('comments')->first();

$entities->remove($post);
$entities->flush(); // DELETE each comment, then the post
```

A managed owner whose owned relationship is uninitialized, or was never
loaded by this manager, is refused with `InvalidEntityStateException`:
skipping it would leave rows the mapping promises to remove, and loading
it would be I/O no property access performs. Load the relationship, or
delete the rows with an explicit operation or a database `ON DELETE
CASCADE` outside this contract.

### Statement order

`flush()` builds one ordered plan before it sends anything:

- the INSERT of a row awaiting insert runs before the INSERT or UPDATE
  that points to it, and that key travels from the one statement to the
  next without ever reaching an entity property;
- an UPDATE that moves or nulls a foreign key away from a row being
  deleted runs before that DELETE;
- the DELETE of a row runs before the DELETE of a row its foreign key
  names;
- an INSERT or UPDATE whose final foreign key names a row the same flush
  removes — one it deletes, or one whose insert an aggregate removal
  cancelled — is refused before SQL, and so is a join row naming one;
- the INSERT of a join row runs after the rows at both of its ends, and
  the DELETE of one runs before the DELETE of its owning entity;
- an owner whose changed join membership advances its version writes that
  membership around its own UPDATE: its join-row DELETEs before it, its
  join-row INSERTs after it (see "Many-to-many relationships").

Where no dependency decides, the order is join-row deletes, entity
deletes, entity inserts, join-row inserts, then updates; entity
statements by class and by identifier where it is known, join-row
statements by join table and by their pair, and both last by the order
they were scheduled in. Deleting first frees the
unique foreign-key slot an owned `#[HasOne]` replacement needs.

### Loops

Rows that reference each other in a loop have no such order. A flush
breaks one by deferring a **nullable** foreign key: the INSERT sends
`NULL` and an UPDATE writes the key once both rows exist, inside the same
transaction. That UPDATE must affect exactly one row; it matches no
version and advances none, because it completes one logical insert before
the row is externally visible, and the committed snapshot holds the final
key. A loop among rows that are all being deleted is nullified the same
way before the DELETEs.

Only a nullable foreign key breaks a loop. A loop of `NOT NULL` columns
throws `InvalidEntityStateException` before a transaction starts, naming
the relationships it runs through: Kinetis does not depend on a
backend's deferred-constraint configuration.

## Many-to-many relationships

`#[ManyToMany]` maps a join table. One side owns it, naming the table and
both of its columns; the other side is optional and only reads it.

```php
use Kinetis\Orm\Attributes\Entity;
use Kinetis\Orm\Attributes\ManyToMany;

#[Entity(table: 'courses')]
final class Course
{
    public int $id;

    public string $title;

    /** @var list<Student> */
    #[ManyToMany(
        target: Student::class,
        table: 'course_student',
        joinColumn: 'course_id',
        inverseJoinColumn: 'student_id',
    )]
    public array $students;
}

#[Entity(table: 'students')]
final class Student
{
    public int $id;

    public string $name;

    /** @var list<Course> */
    #[ManyToMany(target: Course::class, mappedBy: 'students')]
    public array $courses;
}
```

```sql
CREATE TABLE course_student (
    course_id  BIGINT NOT NULL,
    student_id BIGINT NOT NULL,
    PRIMARY KEY (course_id, student_id),
    FOREIGN KEY (course_id)  REFERENCES courses (id),
    FOREIGN KEY (student_id) REFERENCES students (id)
);
```

- **Owning side.** `target`, `table`, `joinColumn` — the column holding
  this entity's identifier — and `inverseJoinColumn` — the column holding
  a target's. It is the only side that writes the table.
- **Inverse side.** `target` and `mappedBy`, the owning `#[ManyToMany]`
  property of that target, and none of the other three: the owning
  property names the table, and this side reads it with its two columns
  swapped. It is a view, like an inverse `#[HasOne]` or `#[HasMany]`.
- **Property.** Exactly `array`, not nullable and with no default value,
  on both sides. `target` names the element class, `self::class`
  included, since PHP cannot declare an element type; a `list<Target>`
  PHPDoc documents it.
- **Schema.** The join table needs a unique constraint over the column
  pair and a foreign key to each entity table. Nothing inspects the
  schema, so a target or an owner another row still names is the
  database's refusal, and so is a duplicate pair the flush could not see:
  one a concurrent writer added, one the table already held, or one a
  separate unit of work created. A duplicate the collection itself holds
  is refused before SQL, as "Writing a join collection" states. Give a
  self-referential mapping two different columns.
- **Refusals.** `MappingException`, when the metadata is built: a type
  other than `array`, a nullable one, a default value, `#[BelongsTo]`,
  `#[HasOne]`, `#[HasMany]`, `#[Column]`, `#[Id]` or `#[Version]` on the
  same property, an owning side missing any of `table`, `joinColumn` and
  `inverseJoinColumn`, an inverse side naming one of them, a table or
  column name that is not an identifier, one column name for both ends, a
  target the registry does not map, and a `mappedBy` naming no
  `#[ManyToMany]` property of the target, an inverse one, or one that
  references another class.

### Loading a join collection

`with('students')` and `with('courses')` load either side, after the root
statement and on the manager's link:

1. One `SELECT` of both join columns where this end's column is `IN` the
   distinct identifiers of the level's entities whose property is
   uninitialized, for at most 1,000 identifiers per statement, ordered by
   both columns. A level without identifiers sends nothing, and a join
   column holding no identifier throws `MappingException`.
2. One `SELECT` of the target's mapped columns where its identifier
   column is `IN` the distinct identifiers those rows name, again at most
   1,000 per statement. There is no join. Every row loads under
   "Loading", so a held target is returned as it is, and an identifier no
   row matches throws `MappingException`.
3. Each property is set to the list of its own rows' targets, in the
   order step 1 read them, and to an empty list without rows. An
   initialized property is never overwritten; what it holds joins the
   next level and must be an entity of the target class this manager
   manages.

A join collection is a set of entity identities, so its array order
carries no meaning and a duplicate is refused before SQL: a collection
holding one target object twice, and two objects naming one row, which
never reach one collection together because a manager holds one object
per identity and refuses a second for one it already holds.

### Writing a join collection

Only the owning side writes, and it writes join rows alone: a link is
never a target's insert, update or deletion.

| The owning collection | What `flush()` writes |
|---|---|
| of an entity awaiting insert | one INSERT per link it holds |
| loaded with `with()` | one DELETE per pair it lost, one INSERT per pair it gained |
| loaded with `with()`, on an owner carrying `#[Version]` | the same, and one UPDATE advancing the owner's version |
| loaded and only reordered | nothing |
| of an owner scheduled for deletion | one DELETE by the join column, before the owner's own |
| uninitialized | nothing |
| initialized by the application on a managed owner | `InvalidEntityStateException` before SQL |

```php
$course = $entities->repository(Course::class)->query()->with('students')->first();

$course->students = [...array_filter($course->students, fn (Student $s) => $s !== $dropped), $enrolled];

$entities->flush(); // DELETE the pair that left, INSERT the pair that joined
```

`$dropped` and `$enrolled` are students this manager holds.

- **A loaded membership is the only baseline.** Assigning an array to a
  managed owner's collection the manager never loaded is refused, naming
  the relationship to load: what the join table holds behind it is
  unknown, and reading it would be I/O no property access performs. A new
  owner's collection needs no load — it is the complete membership to
  insert.
- **Targets are never cascaded.** Every target must already be managed or
  awaiting insert in the same flush; anything else is refused with
  `InvalidEntityStateException`. Dropping a link leaves the target row
  untouched, and removing a target entity writes no join row: the
  database's foreign key refuses that DELETE, or a declared `ON DELETE
  CASCADE` performs that policy.
- **The inverse side is inert.** Changing it writes nothing, exactly as
  an inverse `#[HasOne]` or `#[HasMany]`. Nothing reconciles the two
  sides in memory.
- **A versioned owner locks its membership.** A non-empty difference on a
  managed owner carrying `#[Version]` is a change of that owner, so the
  flush advances its version once: through the UPDATE its changed columns
  already send, or through one that writes the version column alone. Two
  writers replacing one owner's membership therefore conflict instead of
  merging — the second throws `OptimisticLockException` and writes none
  of its links (see "Optimistic locking"). A new owner's INSERT carries
  its initial version and needs no UPDATE, a removed owner's DELETE
  carries the lock, and an owner without `#[Version]` writes join rows
  alone.
- **Order.** A link's INSERT runs after both endpoint rows exist, so a
  generated key travels from its INSERT into the link statement without
  reaching a property before COMMIT. A join table's DELETEs run before
  its INSERTs and before the owner's own DELETE, and the statements of
  one join table are ordered by their pair, so concurrent flushes take
  its row locks in one order. A versioned owner's UPDATE stands between
  them: after its link DELETEs, which keeps its lock order the same as
  its own deletion's, and before its link INSERTs, so a conflict answers
  before a duplicate pair can.
- **Row counts.** A link DELETE may affect any number of rows, zero
  included: a link already gone is nothing left to delete, and an owner's
  cleanup removes whatever names it. A join row has no version of its
  own, so no optimistic lock applies to the row: the unique constraint is
  what refuses a pair the flush cannot see, and the pair it refuses fails
  the flush before COMMIT like any other statement (see "When a flush
  fails").
- **After COMMIT.** The membership the plan was built from becomes the
  baseline the next flush diffs against. A rollback, a rollback failure
  and an unacknowledged COMMIT leave it as it was, so the whole flush
  can be sent again.

A link that carries data of its own — a grade, a position, a
`deleted_at` — is not a join row but an entity: map an `Enrollment` class
with a surrogate identifier and two `#[BelongsTo]` properties, and it
persists, orders and removes under "Aggregates" like any other row.

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
| Not held: new, or detached | false | nothing, unless an owned relationship reaches it (see "Aggregates") |
| Awaiting insert | true | an INSERT |
| Managed | true | an UPDATE of its changed columns, and its next version when a versioned entity's columns or owning join membership changed |
| Scheduled for deletion | true | a DELETE |

- **`persist($entity)`** validates an object the manager does not hold —
  new, or detached from this or another manager — and schedules its
  insert. Its class must be an entity in the factory's metadata, every
  property mapping a column initialized and admitted by the table under
  "Loading" (a non-finite float is not), every `#[BelongsTo]` target an
  entity of that metadata, an assigned identifier not null and not held
  by another object of this manager, and a generated identifier null.
  Which entity each target resolves to is settled by `flush()`, so
  related entities can be persisted in any order (see "Aggregates"). An
  assigned identity enters the identity map at once, so `find()` returns
  the object; a generated one enters it when the insert commits.
  `persist()` leaves an entity awaiting insert or managed as it is, and
  cancels the deletion of one scheduled for deletion and of the aggregate
  below it.
- **`remove($entity)`** schedules a managed entity, and everything below
  it through its owned relationships, for deletion. Until the DELETE
  commits each stays managed, keeps its identity and is what loads of its
  row return, and `persist()` cancels the deletion. An entity awaiting
  insert is detached instead and its insert dropped, without SQL, as is
  every pending entity below it. Any other object is refused, as is an
  owned relationship this manager never loaded (see "Aggregates").
- **Changes.** A managed entity has a snapshot: the values it was loaded
  or last flushed with. Each `flush()` compares every property mapping a
  column with it as a database value — a backed enum as its backing
  value, a `#[BelongsTo]` as its foreign key — so a value changed and changed back
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

1. Before the transaction, it walks every initialized owned relationship
   once and schedules the new entities it reaches (see "Aggregates"),
   reads and validates every entity awaiting insert as `persist()` does
   and every managed entity, refuses an identifier or version that
   changed and an UPDATE whose version cannot advance, reconciles every
   owned relationship it loaded, diffs every owning join collection it
   may write, and orders every statement. With nothing
   to write, it returns without a transaction or any I/O.
2. One INSERT per entity awaiting insert, of every mapped column but a
   generated identifier.
3. One UPDATE of the changed columns, or one DELETE, per entity, by the
   identifier column and, for a versioned entity, the version column (see
   "Optimistic locking"). A versioned owner whose owning `#[ManyToMany]`
   membership changed takes that UPDATE too, of the version column alone
   where no other column changed.
4. An UPDATE per deferred foreign key, where a loop of references needed
   one (see "Loops").
5. One DELETE per join row an owning `#[ManyToMany]` collection dropped,
   one per join table of an entity being deleted, and one INSERT per join
   row such a collection gained (see "Many-to-many relationships").
6. COMMIT.

Statements 2 to 5 run in the one order "Statement order" states: every
row after the rows its foreign keys name and a versioned owner's UPDATE
between the join rows it changed, and otherwise join-row deletes, entity
deletes, entity inserts, join-row inserts then updates, by class,
identifier and join pair, so concurrent flushes take row
locks in one order where no dependency decides. Every statement runs on
that transaction, and nothing is batched. A statement that needs a key an
earlier INSERT generated takes it from that INSERT; no entity property
holds it before COMMIT.

An entity's DELETE and a deferred foreign key's UPDATE must affect
exactly one row, and an entity's UPDATE at most one; a join row's DELETE
may affect any number. An unversioned entity UPDATE
affecting none is followed by an existence check on the same transaction: the MySQL family counts changed rows rather than
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
written into its property; every reconciled owned relationship and every
written join collection takes the membership the plan was built from; and
a deleted entity is detached.

While `flush()` runs, the manager refuses every call but `close()` and
`isClosed()` with `InvalidEntityStateException`, including a call made on
the flushing Fiber by code the flush reaches, such as SQL instrumentation.

### When a flush fails

This table covers a manager from `open()`. A flush inside a transaction
session fails the session instead: see "When a session fails".

| Failure | Afterwards | Pending work | Throws |
|---|---|---|---|
| Validation, or `beginTransaction()` | open | kept | that exception |
| Anything before COMMIT, with the rollback returning | open, unless `close()` ran | kept | that exception, unwrapped: `OptimisticLockException`, or a driver `SqlException` as the driver threw it — a `QueryException`, a `ConnectionException`, or a `TransactionException` from a transaction the server already ended |
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

`flush()`'s `@throws` names `Kinetis\Persistence\Exception\SqlException`
for that whole unwrapped family, so a caller may catch any member of it.
To recover from a unique key conflict, catch `QueryException` and ask
`isUniqueViolation()`, which
[`kinetis/persistence`](https://github.com/kinetis-dev/persistence)'s
README defines for every driver.

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
- **Owning join membership.** A changed owning `#[ManyToMany]` collection
  is a change of its owner, so it takes that same UPDATE and that same
  one increment, however many of the owner's collections changed; with no
  column changed, the UPDATE writes the version column alone (see
  "Many-to-many relationships"). An owned inverse relationship is not:
  adding, moving or removing children writes their own rows and advances
  no owner version.
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

## Timestamps

```php
use DateTimeImmutable;
use DateTimeZone;
use Kinetis\Orm\Attributes\Entity;

#[Entity(table: 'shipments')]
final class Shipment
{
    public ?DateTimeImmutable $deliveredAt = null; // delivered_at DATETIME(6) NULL

    public function __construct(
        private int $id,
        private DateTimeImmutable $shippedAt, // shipped_at DATETIME(6) NOT NULL
    ) {}
}

$entities->repository(Shipment::class)
    ->query()
    ->where('shippedAt', '>=', new DateTimeImmutable('2026-03-04 00:00', new DateTimeZone('Europe/Paris')))
    ->get(); // shipped_at >= ?, bound as '2026-03-03 23:00:00.000000'
```

- **Mapping.** A property declared exactly `DateTimeImmutable` or
  `?DateTimeImmutable` maps a timestamp column, recorded in the metadata
  with the type `timestamp`. It is never the identifier or the version.
  `DateTime`, `DateTimeInterface` and a subclass of `DateTimeImmutable`
  are refused as declared types; a subclass instance held by a
  `DateTimeImmutable` property is written by its instant like any other
  value.
- **Column.** A timestamp column holds a UTC value without a time zone,
  to the microsecond: `DATETIME(6)` on MySQL and MariaDB, and
  `timestamp(6) without time zone` on PostgreSQL, whose `DateStyle` must
  be `ISO`, the server default. Nothing inspects the schema, sets a
  session time zone or `DateStyle`, or generates a timestamp.
- **Narrower columns.** A column with fewer than six fraction digits
  rounds (MySQL, PostgreSQL) or truncates (MariaDB) the instant written,
  so the stored row differs from the entity and an equality predicate on
  the written value matches no row. The manager keeps the value it sent,
  so the difference shows when a later unit of work loads the row.
- **Unsupported columns.** PostgreSQL's `timestamptz` and a non-ISO
  `DateStyle` return spellings with an offset or in another format, and
  loading one throws `MappingException`. MySQL's `TIMESTAMP` converts
  every value through the session `time_zone` and returns the spelling
  `DATETIME` does, so nothing can detect it: it is not supported.
- **Writing.** The database value is the instant in UTC, formatted
  `Y-m-d H:i:s.u` (`2026-03-04 05:06:07.123456`) and bound as a string.
  Its UTC year must be 0001 to 9999; any other throws `MappingException`
  before SQL, from `persist()`, `flush()` or a predicate. A six-digit column on MySQL 8.4, MariaDB
  11.4 and PostgreSQL 16 stores, returns and compares that whole range
  exactly, though the MySQL family's date arithmetic functions are
  unreliable below year 1000.
- **Changes.** The snapshot holds that string, so assigning the same
  instant in another zone is not a change, and one a microsecond apart
  is.
- **Loading.** A driver value is `YYYY-MM-DD HH:MM:SS`, optionally
  followed by `.` and one to six fraction digits, and is read as UTC;
  PostgreSQL omits trailing fraction zeros, and the MySQL family prints
  the column's precision. A date or time that does not exist, such as
  February 30 or 24:00:00, year 0000, an offset, a `BC` suffix,
  surrounding whitespace or any other text throws `MappingException`.
  The property receives a `DateTimeImmutable` in UTC, whatever the
  process's default time zone.
- **Predicates.** `where()`, `whereIn()` and `findBy()` take a
  `DateTimeImmutable` in any zone, or a string the loading rule admits,
  and bind its database value; anything else, such as a string with an
  offset, throws `MappingException` before SQL.
- **Cursors.** A `cursorPaginate()` cursor on a timestamp property is
  admitted and bound the same way, so the driver spelling a page returns
  as `nextCursor` binds as its six-digit UTC string, and a malformed
  cursor, an offset, a year outside 0001 to 9999 or a date that does not
  exist throws `MappingException` before SQL. A null cursor starts at
  the first page. A cursor on any other property is bound as given.

## Dates

```php
use Kinetis\Orm\Attributes\Entity;
use Kinetis\Orm\Date;

#[Entity(table: 'bookings')]
final class Booking
{
    public ?Date $cancelledOn = null; // cancelled_on DATE NULL

    public function __construct(
        private int $id,
        private Date $arrivesOn, // arrives_on DATE NOT NULL
    ) {}
}

new Date(2026, 9, 25);          // (string) '2026-09-25'
Date::fromString('2024-02-29'); // ->year 2024, ->month 2, ->day 29

$entities->repository(Booking::class)
    ->query()
    ->where('arrivesOn', '>=', new Date(2026, 9, 1))
    ->get(); // arrives_on >= ?, bound as '2026-09-01'
```

- **Value.** `Kinetis\Orm\Date` is a final readonly calendar date: the
  integer properties `year`, `month` and `day`, and no time, time zone or
  instant. `new Date($year, $month, $day)` admits a day that exists in
  the Gregorian calendar in years 0001 to 9999, leap days included, and
  throws `InvalidArgumentException` for any other.
  `Date::fromString($value)` admits exactly `YYYY-MM-DD`, each field
  zero-padded, and nothing before or after it; a timestamp, an offset,
  whitespace or a day the constructor refuses throws
  `InvalidArgumentException`, whose message never quotes the string.
  Casting a `Date` to string gives that same `YYYY-MM-DD` form.
- **Mapping.** A property declared exactly `Date` or `?Date` maps a date
  column, recorded in the metadata with the type `date`. It is never the
  identifier or the version. The declared type alone decides it: a
  `DateTimeImmutable` property stays a timestamp, and a class of another
  namespace named `Date` is refused like any other object.
- **Column.** A date column is SQL `DATE` on MySQL, MariaDB and
  PostgreSQL, whose `DateStyle` must be `ISO`, the server default.
  Nothing inspects the schema.
- **Different from timestamps.** A `Date` names a day, not an instant:
  it is never converted through a time zone, never becomes midnight, and
  a timestamp property does not admit it, nor a date property a
  `DateTimeImmutable`.
- **Loading.** A driver value is `YYYY-MM-DD` and the property receives a
  `Date`; a row value that already is a `Date` is admitted as it is.
  Null is admitted where the property is nullable. A timestamp, an
  offset, a `BC` suffix, surrounding whitespace, year 0000, a year of
  more than four digits, a day that does not exist, such as February 30
  or `0000-00-00`, or any other value throws `MappingException`, which
  names the value's type and never the value.
- **Writing and changes.** The database value is `YYYY-MM-DD`
  (`2026-09-25`), bound as a string by `flush()`. The snapshot holds that
  string, so assigning another `Date` of the same day is not a change.
- **Predicates.** `where()`, `whereIn()` and `findBy()` take a `Date` or
  a string the loading rule admits and bind its database value; anything
  else throws `MappingException` before SQL.
- **Cursors.** A `cursorPaginate()` cursor on a date property is
  admitted and bound the same way, and a malformed one throws
  `MappingException` before SQL.

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

Join-table payload, ordering or soft deletion, cascading a target's
persistence or removal through a join table, foreign-key writes through
an inverse relationship or fixup of either side, cascades a relationship
does not own, cascade rules per operation, orphan removal through a
relationship this manager never loaded,
collection objects or mutation APIs, lazy loading or proxies, joined
eager loading, streamed, capped or partial collections, predicates on a
target's properties, timestamp,
string or database-generated versions, refreshing or merging an entity,
conflict resolution, relationships, flushes or transactions across
connections, routing an entity to another connection at run time,
replicas or sharding, joining a transaction the application began, nested
sessions or savepoints, more than one writing flush per session,
provisional identifiers or versions, batched or bulk writes,
automatic retries, flushing on `close()` or destruction, automatic
timestamps, `DateTime` properties, `timestamptz` or MySQL `TIMESTAMP`
columns, time-only values, date arithmetic, time zone or precision options,
custom value converters, UUID generation,
composite identifiers, inheritance, partial entities, transient
properties, schema validation, CLI commands, streaming, or static model
methods.

## Static analysis

The ORM accesses every mapped property through reflection: it reads a
column's and a relationship owner's value while it plans and flushes,
and it writes a hydrated column, a generated identifier once the insert
commits, and a loaded relationship. PHPStan sees none of that hidden use
and reports a property the application only writes as
`property.onlyWritten`. `isAlwaysRead()` is the only extension answer
that clears that false positive, so registering this package's
extension tells PHPStan that every non-static property of an
`#[Entity]` class is read:

```yaml
# phpstan.neon
includes:
    - vendor/kinetis/orm/extension.neon
```

There is no extension installer to do it: add the `includes:` entry by
hand.

That exempts those properties from `property.unusedType` as well, a
generated identifier's `?int` included: PHPStan stops checking a
property's type as soon as an extension calls it always read, and offers
no narrower answer. The exemption reaches nothing but `#[Entity]`
classes, and a static property on one stays reported.

## Installation

```sh
composer require kinetis/orm
```

Requires PHP 8.4+ and the extension for the driver you use (see
[`kinetis/persistence`](https://github.com/kinetis-dev/persistence)). In
a Kinetis application,
[`kinetis/database-bridge`](https://github.com/kinetis-dev/database-bridge)
compiles the entity metadata, binds an `OrmFactoryRegistry` for the worker
and an `EntityManagerRegistry` for each request over every connection an
entity names, and binds `OrmFactory` and the request's `EntityManager` as
the default connection's entries of the two.
Full documentation:
[kinetis.dev/docs/orm.html](https://kinetis.dev/docs/orm.html).

## License

MIT — see [LICENSE](LICENSE).
