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
the repository and query API, relationships, aggregates, writing and
flushing, optimistic locking, transaction sessions, and what it does not
do — is
the [package README](https://github.com/kinetis-dev/orm#readme). This
page covers using it in an application.
````

A data mapper over {doc}`query-builder`: classes marked `#[Entity]` load
through typed repositories and entity queries without running their
constructors, and each unit of work holds one object per row, tracks
changes to it, and writes new, changed and removed entities in one
transaction on `flush()`. Entities reference each other through explicit
`#[BelongsTo]`, `#[HasOne]` and `#[HasMany]` relationships — an inverse
one marked `owned` makes a whole aggregate persist and remove together —
or across a join table with `#[ManyToMany]`, and a `#[Version]` property
adds optimistic locking.

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
the project's production `autoload.psr-4` roots and every installed
package's `scan` roots, keeps the classes carrying `#[Entity]`, and maps
exactly those with `MetadataRegistry`. The result is one section of the
AOT artifact ({doc}`caching`): development discovers entities live at
each boot, and production reads what `kinetis build` compiled. No environment
key narrows the scan.

An entity the mapper refuses fails `kinetis build`, or a development
boot, with its `MappingException`. A cached section that no longer
matches the entity classes, or that was compiled before `kinetis/orm`
was installed or removed, is stale: the boot compiles fresh, as for any
other section.

An entity opts into optimistic locking with `#[Version]` under the
README's "Optimistic locking" contract, and declares relationships with
`#[BelongsTo]`, `#[HasOne]`, `#[HasMany]` — `owned` included — and
`#[ManyToMany]` under its "Relationships", "Aggregates" and
"Many-to-many relationships" contracts; the bridge adds nothing
to any of them. A relationship's target
must be an entity the same scan finds, on the same connection. Every one of these attributes is
part of the compiled metadata, so run `kinetis build` again after
adding, removing or moving one.

## Map entities and relationships

Eight attributes cover the ORM's mapping. `#[Entity]`, `#[Column]`,
`#[Id]` and `#[Version]` describe an entity's own table; `#[BelongsTo]`,
`#[HasOne]`, `#[HasMany]` and `#[ManyToMany]` describe where it
references another. The examples below show every constructor argument,
all as named arguments. "Write a whole aggregate" and "Link rows across
a join table" later on cover lifecycle, loading and refusal rules in
context.

### `#[Entity]`

```{code-block} php
#[Entity(table: 'articles', connection: 'default')]
final class Article
```

Marks a class as an entity; required on every one. `table` is optional:
unnamed, the table is the class's short name in snake case, singular
(`ArticleCategory` maps to `article_category`). A dot separates a schema
from the table (`table: 'reporting.articles'`). `connection` is optional
too: unnamed, the entity lives on the default connection, and a name —
lowercase ASCII letters and digits, starting with a letter — puts it on
that named connection ([Entities on other
connections](#entities-on-other-connections)).

The ORM maps every non-static property of an entity and accesses each
one through reflection: it reads a column's and a relationship owner's
value while it plans and flushes, and it writes a hydrated column, a
generated identifier once the insert commits, and a loaded relationship.
PHPStan sees none of that hidden use, so register this package's
extension to tell it that every mapped property is read:

```{code-block} yaml
:caption: phpstan.neon

includes:
    - vendor/kinetis/orm/extension.neon
```

The README's "Static analysis" is the complete contract, including what
registering it costs.

### `#[Column]`

```{code-block} php
#[Column(name: 'author')]
private int $authorId;
```

Overrides one property's column name. `name` is optional: unnamed, the
column is the property name in snake case (`publishedAt` maps to
`published_at`). Name it when the table's column does not already follow
that convention.

### `#[Id]`

```{code-block} php
#[Id(generated: true)]
private ?int $id = null;
```

Marks the identifier property; without it, the property named exactly
`id` is the identifier. `generated` is optional and defaults to `false`:
the application assigns the identifier before `persist()`. `generated:
true` leaves it to the database — a MySQL/MariaDB `AUTO_INCREMENT` or a
PostgreSQL identity column — and requires the property typed `?int`,
holding null until the entity's insert commits.

### `#[Version]`

```{code-block} php
#[Version]
private int $version = 1; // a signed BIGINT NOT NULL column
```

Takes no constructor arguments. Marks the one property, typed non-null
`int` and not the identifier, that opts the entity into optimistic
locking: the application initializes it, conventionally to `1`, and the
ORM never infers, defaults, generates or reads one back. The README's
"Optimistic locking" is the complete contract.

### `#[BelongsTo]`

```{code-block} php
#[BelongsTo(column: 'written_by')]
private Author $author;
```

Names the foreign-key column on this entity's own table, holding the
target's identifier. `column` is optional: unnamed, it is the property
name in snake case followed by `_id` (`$author` maps to `author_id`).

### `#[HasOne]`

```{code-block} php
#[HasOne(mappedBy: 'author', owned: true)]
private ?Profile $profile;
```

Names the one entity whose `#[BelongsTo]` property `mappedBy` references
this entity. `mappedBy` is required; the target is inferred from the
property's own type, not named on the attribute. `owned` is optional and
defaults to `false` — "Write a whole aggregate" below covers what it
changes.

### `#[HasMany]`

```{code-block} php
#[HasMany(target: InvoiceLine::class, mappedBy: 'invoice', owned: true)]
private array $lines;
```

Names every entity whose `#[BelongsTo]` property `mappedBy` references
this entity. `target` and `mappedBy` are both required: PHP's `array`
type cannot name the element class, so `target` does. `owned` is
optional and defaults to `false`.

### `#[ManyToMany]`

The owning side:

```{code-block} php
#[ManyToMany(
    target: Student::class,
    table: 'course_student',
    joinColumn: 'course_id',
    inverseJoinColumn: 'student_id',
)]
private array $students;
```

The inverse side:

```{code-block} php
#[ManyToMany(target: Course::class, mappedBy: 'students')]
private array $courses;
```

`target` is always required. The owning side also requires `table`, the
join table's name; `joinColumn`, the join-table column holding this
entity's identifier; and `inverseJoinColumn`, the column holding a
target's. The inverse side instead names the owning property with
`mappedBy` and takes none of the other three — an owning and an inverse
form are mutually exclusive on one property. "Link rows across a join
table" below is the complete contract.

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

- `Kinetis\Orm\OrmFactory` is the default connection's factory of the
  worker's `OrmFactoryRegistry`, which is bound on `AppScope` and built on
  first use from the link bound under `MysqlLink` or `PostgresLink` — an
  application's own binding of it in `bootstrap.php` included — and the
  `OrmMetadata` bound then: the compiled metadata, or an application's
  replacement.
- `Kinetis\Orm\EntityManager` is the default connection's manager of the
  `EntityManagerRegistry` bound on every `RequestScope`: an HTTP request,
  a queued job, an MCP message, a command. The first resolution in a
  scope creates that registry, owned by the Fiber resolving it, and
  registers its `close()` on that scope's disposal; the registry opens
  each connection's manager on first use. A scope that never resolves
  either opens none. Sequential and concurrent units of work never share
  a manager or an entity.
- `EntityManager` and `EntityManagerRegistry` are never `AppScope`
  services. Their constructors are not public, so `AppScope` refuses to
  autowire one rather than keep a manager for the life of the worker.
  Code that holds a manager is request-scoped.

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

A predicate naming a `#[BelongsTo]` property takes the related entity's
identifier value, not the entity object:

```{code-block} php
$comments->query()
    ->where('article', '=', $article->id())
    ->get();
```

The value converts through the target's identifier type. Passing the related
entity object throws `MappingException` before SQL runs.

## Write a whole aggregate

An inverse relationship marked `owned` ties a parent and its children
into one unit: the parent marks the relationship, and the child keeps the
foreign key.

```{code-block} php
use Kinetis\Orm\Attributes\BelongsTo;
use Kinetis\Orm\Attributes\Entity;
use Kinetis\Orm\Attributes\HasMany;
use Kinetis\Orm\Attributes\Id;

#[Entity(table: 'invoices')]
final class Invoice
{
    #[Id(generated: true)]
    private ?int $id = null;

    /** @var list<InvoiceLine> */
    #[HasMany(target: InvoiceLine::class, mappedBy: 'invoice', owned: true)]
    private array $lines;

    public function __construct(private int $customerId)
    {
        $this->lines = [];  // a loaded invoice's lines are whatever with('lines') read
    }

    public function add(InvoiceLine $line): void
    {
        $this->lines[] = $line;     // the line's constructor set the foreign key
    }

    public function drop(InvoiceLine $line): void
    {
        $keep = static fn (InvoiceLine $held): bool => $held !== $line;
        $this->lines = array_values(array_filter($this->lines, $keep));
    }
}

#[Entity(table: 'invoice_lines')]
final class InvoiceLine
{
    #[Id(generated: true)]
    private ?int $id = null;

    #[BelongsTo]
    private Invoice $invoice;   // invoice_id, NOT NULL

    public function __construct(Invoice $invoice, private string $sku, private int $quantity)
    {
        $this->invoice = $invoice;
    }
}
```

One `persist()` writes the graph in one transaction, in an order the
foreign keys accept: the invoice row first, then its key in each line.
The lines need none of their own, and `$input` is the application's own
validated input ({doc}`routing-validation`).

```{code-block} php
$invoice = new Invoice($input->customerId);

foreach ($input->lines as $line) {
    $invoice->add(new InvoiceLine($invoice, $line->sku, $line->quantity));
}

$this->entities->persist($invoice);
$this->entities->flush();   // INSERT the invoice, then one INSERT per line
```

Changing the aggregate works on a relationship the request **loaded**:
that membership is what the flush compares against.

```{code-block} php
$invoice = $this->entities->repository(Invoice::class)
    ->query()
    ->where('id', '=', $id)
    ->with('lines')         // the membership the flush reconciles against
    ->first();

$invoice->drop($discontinued);
$invoice->add(new InvoiceLine($invoice, $sku, 1));

$this->entities->flush();   // DELETE the dropped row, INSERT the new one
```

- **`with()` is the permission to remove.** An owned relationship this
  manager never loaded still discovers and inserts new children but
  removes nothing, and `remove()` on such an owner is refused.
- **The child's foreign key decides.** A dropped line whose
  `#[BelongsTo]` names another invoice is moved by an UPDATE rather than
  deleted, and the receiving invoice must hold it when its own collection
  is loaded. Nothing reconciles the two sides.
- **`remove($invoice)`** schedules the invoice and every line below it,
  children first. A generated key reaches its object only once `COMMIT`
  returns, and a loop of `NOT NULL` foreign keys is refused before SQL
  while a nullable one is deferred inside the transaction.

The README's "Aggregates" is the complete contract, including statement
order, orphan and reparenting rules, and every refusal.

## Link rows across a join table

`#[ManyToMany]` maps entities that reference each other through a join
table and that no one owns. One side owns the table, naming it and both
of its columns; the other is optional and reads it.

```{code-block} php
use Kinetis\Orm\Attributes\Entity;
use Kinetis\Orm\Attributes\Id;
use Kinetis\Orm\Attributes\ManyToMany;

#[Entity(table: 'courses')]
final class Course
{
    #[Id(generated: true)]
    private ?int $id = null;

    /** @var list<Student> */
    #[ManyToMany(
        target: Student::class,
        table: 'course_student',
        joinColumn: 'course_id',            // this entity's identifier
        inverseJoinColumn: 'student_id',    // a target's
    )]
    private array $students;

    public function __construct(private string $title)
    {
        $this->students = [];   // a loaded course's roll is whatever with('students') read
    }

    public function enrol(Student $student): void
    {
        // A join collection is a set, and the ORM refuses one object twice.
        if (!in_array($student, $this->students, true)) {
            $this->students[] = $student;
        }
    }

    public function withdraw(Student $student): void
    {
        $keep = static fn (Student $held): bool => $held !== $student;
        $this->students = array_values(array_filter($this->students, $keep));
    }
}

#[Entity(table: 'students')]
final class Student
{
    #[Id(generated: true)]
    private ?int $id = null;

    /** @var list<Course> */
    #[ManyToMany(target: Course::class, mappedBy: 'students')]
    private array $courses;     // the same table, read from this end

    public function __construct(private string $name)
    {
        $this->courses = [];
    }
}
```

Create the join table with a migration ({doc}`migrations`). Nothing
inspects the schema: its unique key refuses a pair another writer added,
its foreign keys an end that does not exist. A join row carries no
version of its own.

```{code-block} sql
CREATE TABLE course_student (
    course_id  BIGINT NOT NULL,
    student_id BIGINT NOT NULL,
    PRIMARY KEY (course_id, student_id),
    FOREIGN KEY (course_id)  REFERENCES courses (id),
    FOREIGN KEY (student_id) REFERENCES students (id)
);
```

Attaching and detaching is mutating the **owning** collection: load it
with `with()`, and the flush writes the difference.

```{code-block} php
$course = $this->entities->repository(Course::class)
    ->query()
    ->where('id', '=', $id)
    ->with('students')      // the membership the flush diffs against
    ->first();

$students = $this->entities->repository(Student::class);
$joining = $students->findOrFail($joiningId);
$leaving = $students->findOrFail($leavingId);

$course->enrol($joining);
$course->withdraw($leaving);

// One INSERT and one DELETE in course_student, and no student row.
$this->entities->flush();
```

- **`with()` is the permission to write.** A collection this manager
  never loaded has no known database state, so assigning one on an entity
  it loaded is refused. A new course's collection needs no load: its
  links go in the flush that inserts it.
- **Links only.** A flush writes join rows and never inserts, updates or
  deletes a target: every student must already be managed or persisted,
  detaching one leaves its row untouched, `remove($course)` deletes its
  join rows first, and removing a student is the join table's foreign key
  or its `ON DELETE CASCADE` to decide.
- **The inverse side reads.** `with('courses')` loads the same rows from
  the student's end; changing `Student::$courses` writes nothing.
- **A versioned owner locks its roll.** When `Course` carries
  `#[Version]`, a changed membership advances that version under the same
  optimistic lock its columns take, so two writers replacing one course's
  roll conflict with `OptimisticLockException` instead of merging. The
  README's "Many-to-many relationships" and "Optimistic locking" are the
  contract.

A link with a grade, a position, a `deleted_at` or any other column of
its own is not a join row but an entity. Map it with a surrogate
identifier and a `#[BelongsTo]` to each side, replace the course's
`#[ManyToMany]` with an owned `#[HasMany]` to it, and the aggregate rules
above apply unchanged — the unique key on the column pair kept, since it
is still what makes one link one pair.

The README's "Many-to-many relationships" is the complete contract.

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
  `RollbackFailedException` closes the manager instead. The driver's own
  failure arrives unwrapped, so `flush()` throws
  `Kinetis\Persistence\Exception\SqlException`; to settle a race on a
  unique key, catch `QueryException` and ask `isUniqueViolation()`
  ({doc}`persistence`).
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

An outbox row for {doc}`queue-sql` follows the same rule:
`$entities->builder()` runs a statement when called, on this
transaction, while a pending entity's INSERT is emitted only by the
closing `flush()` above. A builder row whose foreign key names a
still-pending entity fails regardless of how its id is assigned — the
referenced row is not there yet on this connection. A generated
identifier is additionally unavailable until `COMMIT` returns, since it
reaches the object only then.

Map the outbox row as an entity instead, with a `#[BelongsTo]` naming
the entity it depends on, and persist both:

```{code-block} php
$order = new Order();
// ...

$outbox = new OutboxRow();
$outbox->order = $order;   // #[BelongsTo] — order_id

$entities->persist($order);
$entities->persist($outbox);
$entities->flush();        // INSERT order, then outbox, one transaction
```

Entities persisted separately need no ordering of their own: `flush()`
orders every pending row by its foreign keys and inserts the entity a
`#[BelongsTo]` names before the row that references it, on every
supported backend — the README's "Aggregates" is the complete contract.

`QueueInterface::push()` inside the callback is wrong here: it runs on
the queue's own connection rather than this one, so it is not enlisted
in the transaction and a rollback does not undo it. Publish the pending
outbox rows once `transaction()` returns and mark them sent; [A job can
run more than once](queue.md#a-job-can-run-more-than-once) owns replay
and idempotency for that step.

## Without a database

With `kinetis/orm` installed and `DB_CONNECTION` unset, the application
still boots. Resolving `OrmFactory` or `EntityManager` throws
`Kinetis\DatabaseBridge\Exception\DatabaseNotConfiguredException`, which
names `DB_CONNECTION`; so does resolving either registry while an entity
lives on the default connection. Entities that all live on named
connections work through the registries alone.

## Entities on other connections

An entity names the database it lives on, and the bridge wires every
connection an entity names:

```{code-block} php
use Kinetis\Orm\Attributes\Entity;

#[Entity(table: 'orders', connection: 'reporting')]
final class Order
{
    private int $id;

    private int $total;

    public function total(): int
    {
        return $this->total;
    }
}
```

```{code-block} text
:caption: .env

DB_REPORTING_CONNECTION=pgsql
DB_REPORTING_HOST=reporting.internal
DB_REPORTING_NAME=reports
DB_REPORTING_USER=reports
DB_REPORTING_PASSWORD=secret
```

A request-scoped class injects `EntityManagerRegistry` and asks it for
the manager of an entity's connection:

```{code-block} php
use Kinetis\Http\Attributes\Get;
use Kinetis\Orm\EntityManagerRegistry;

final readonly class OrderController
{
    public function __construct(private EntityManagerRegistry $entities) {}

    #[Get('/orders/{id}')]
    public function show(int $id): array
    {
        $order = $this->entities->managerFor(Order::class)->repository(Order::class)->findOrFail($id);

        return ['total' => $order->total()];
    }
}
```

- **The link.** For each connection an entity names, the bridge uses the
  application's `db.<name>` binding from `bootstrap.php` when there is
  one: it must be a `MysqlLink` or `PostgresLink`, and it stays the
  application's to close. Otherwise it builds the connection with
  `ConnectionFactory::fromConfig($config, '<name>')` from the
  `DB_<NAME>_*` keys ({ref}`database-reference-registration`) and closes
  it when the application scope is disposed. With neither, resolving the
  registry throws `DatabaseNotConfiguredException` naming
  `DB_<NAME>_CONNECTION` — checked before any other key of that
  connection is read.
- **One registry for the worker.** `OrmFactoryRegistry` is built on
  first use with every link at once, so each connection an entity names
  must be configured or bound before any ORM service resolves,
  `OrmFactory` and `EntityManager` included. It also holds a factory for
  the default connection whenever `DB_CONNECTION` is set, whether or not
  an entity lives there.
- **One manager per connection per unit of work.** The request's
  `EntityManagerRegistry` opens `manager('reporting')`, or
  `managerFor(Order::class)`, once, and closes every manager it opened
  when the scope is disposed, without flushing. Another Fiber is refused
  with `CrossFiberAccessException`.
- **Nothing spans two connections.** A relationship between entities on
  two connections fails `kinetis build`, or a development boot, with a
  `MappingException` naming both classes and both connections. Each
  manager flushes one transaction on its own connection, and
  `OrmFactoryRegistry::factory('reporting')->transaction()` runs on that
  connection alone: a write to two databases is two units of work, and
  the second can fail after the first committed.

The package README's "Connections" is the complete contract.
`OrmFactory::transaction()` begins and ends its own transaction on the
factory's link, on every way out, so the request's `TransactionGuard`
neither tracks nor needs to roll it back.

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
