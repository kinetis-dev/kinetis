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
`#[BelongsTo]`, `#[HasOne]`, `#[HasMany]` — `owned` included — and
`#[ManyToMany]` under its "Relationships", "Aggregates" and
"Many-to-many relationships" contracts; the bridge adds nothing
to any of them. A relationship's target
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
its foreign keys an end that does not exist.

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
