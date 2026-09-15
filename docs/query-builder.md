# Query Builder

````{note}
Not part of core. Install it separately:

```{code-block} sh
composer require kinetis/query-builder
```
````

A parameterized SQL builder over the connections {doc}`persistence` sets
up. It compiles the SQL that MySQL 8.4, MariaDB 11.4 and PostgreSQL 16
share, runs it on the connection or transaction you pass in, and maps
rows into typed DTOs. Anything outside that shared surface stays
available as raw SQL on the same connection.

It is not an ORM: no relationships, identity map, change tracking or
schema builder. {doc}`orm` loads and writes entities through it. This
page covers everyday queries; {doc}`appendix-query-builder` holds the
complete contract, including subqueries, set operations, CTEs, dialect
spellings and every refused combination.

## Quick start

With `kinetis/database-bridge` configured, a repository injects the
connection and builds one `Query` per statement:

```{code-block} php
use Kinetis\Persistence\Contract\MysqlLink;
use Kinetis\QueryBuilder\Query;

// Application-owned row DTO: one constructor parameter per selected column.
final readonly class ArticleRow
{
    public function __construct(
        public int $id,
        public string $title,
        public string $slug,
    ) {}
}

final readonly class ArticleRepository
{
    public function __construct(private MysqlLink $db) {}

    /** @return list<ArticleRow> */
    public function latest(int $authorId): array
    {
        return new Query($this->db)
            ->table('articles')
            ->select('id', 'title', 'slug')
            ->where('author_id', '=', $authorId)
            ->where('status', '=', 'published')
            ->orderBy('published_at', 'desc')
            ->limit(20)
            ->get(ArticleRow::class);
    }
}
```

`new Query($link)` takes a connection or an open transaction, and the
link's type picks the SQL spelling: a `MysqlLink` compiles for MySQL and
MariaDB, a `PostgresLink` for PostgreSQL. Builder methods such as
`table()`, `where()` and `join()` add to the query and return it; terminal
methods such as `get()`, `count()` and `insert()` run SQL.

```{warning}
One `Query` is one statement: nothing resets between terminals. Create a
fresh `new Query($link)` for every statement.
```

```{warning}
Pass user input only through value slots — a `where()` value, an insert
or update map, a raw fragment's `$params` — which reach the database as
bound parameters or as literals the builder writes itself
({ref}`query-builder-reference-values`). Identifiers (table and column
names) are quoted but not validated, and raw SQL fragments are inserted
as written, so never build either from user input.
```

## Reading rows

```{code-block} php
$rows = new Query($db)->table('articles')->where('status', '=', 'published')->get();
// list<array<string, mixed>>

$articles = new Query($db)->table('articles')->where('status', '=', 'published')->get(ArticleRow::class);
// list<ArticleRow>

$article = new Query($db)->table('articles')->where('slug', '=', $slug)->first(ArticleRow::class);
// ArticleRow|null

$title = new Query($db)->table('articles')->where('id', '=', $id)->value('title');           // the first row's column, or null
$slugs = new Query($db)->table('articles')->select('slug')->orderBy('slug')->pluck('slug'); // list<mixed>
$taken = new Query($db)->table('articles')->where('slug', '=', $slug)->exists();             // bool
```

A DTO class passed to `get()`, `first()`, `paginate()` or
`cursorPaginate()` receives each row through its constructor: every
parameter takes the column of exactly its name, extra columns are
ignored, and a missing column falls back to the parameter's default.
`int`, `float`, `bool` and backed-enum parameters also accept the string
forms drivers deliver, such as `"42"` for an `int`; nothing else is
converted. Select a scalar and convert it in the constructor, or read the
row as an array. {ref}`query-builder-reference-mapping` lists every
admitted value and failure.

### Choosing columns

```{code-block} php
$rows = new Query($db)
    ->table('articles', as: 'a')
    ->join('users', 'u.id', '=', 'a.author_id', as: 'u')
    ->select('a.id', 'a.title', 'u.username')
    ->selectRaw('LENGTH(a.body) > ? AS is_long', [2000])
    ->get();
```

`select()` replaces the column list, which defaults to `*`; each
dot-separated segment is quoted, and a trailing `.*` stays a wildcard.
`table()`'s and `join()`'s `as:` quote an alias. `selectRaw()` appends an
expression and binds its `?` placeholders, and `distinct()` compiles
`SELECT DISTINCT`. `value()` and `pluck()` read a column by its result
name, so a selected `articles.slug` is read as `'slug'`.

## Filtering

```{code-block} php
use Kinetis\QueryBuilder\Conditions;

$rows = new Query($db)
    ->table('articles')
    ->where('status', '=', 'published')
    ->where('deleted_at', '=', null)
    ->whereIn('category', ['news', 'guides'])
    ->whereBetween('published_at', '2026-01-01', '2026-06-30')
    ->whereGroup(fn (Conditions $group) => $group
        ->where('author_id', '=', $authorId)
        ->orWhere('featured', '=', true))
    ->get();
```

- **Operators** are `=`, `!=`, `<>`, `<`, `<=`, `>`, `>=`, `LIKE` and
  `NOT LIKE`, case-insensitive; anything else throws
  `InvalidArgumentException`. An operator cannot be bound as a
  parameter, so a filterable API that passes request values into one
  relies on this check.
- **`AND` and `OR`.** Predicates join with `AND`. `orWhere()`,
  `orWhereColumn()`, `orWhereBetween()` and a `$boolean` argument of
  `'OR'` join with `OR`. Mix the two inside `whereGroup()` or
  `orWhereGroup()`, whose callback receives a `Conditions` object with
  the filtering methods and nothing else.
- **Null.** A null compiles `=` to `IS NULL` and `!=`/`<>` to
  `IS NOT NULL`; any other operator against null throws
  `InvalidArgumentException`.
- **Lists.** An empty `whereIn()` list matches no row, and an empty
  `whereNotIn()` list matches every row. A null inside a list, or as a
  `BETWEEN` bound, is refused.
- **Columns and raw predicates.**
  `whereColumn('updated_at', '>', 'published_at')` compares two columns,
  and `whereRaw('LOWER(title) LIKE ?', [$pattern])` adds a raw predicate.
- **Subqueries.** `whereExists()`, and `whereIn()` given a `Query`, are
  covered in {ref}`query-builder-reference-subqueries`.

## Joins

```{code-block} php
$rows = new Query($db)
    ->table('articles')
    ->join('users', 'users.id', '=', 'articles.author_id')
    ->leftJoin('images', 'images.article_id', '=', 'articles.id')
    ->joinOn('follows', fn (Conditions $on) => $on
        ->whereColumn('follows.followee_id', '=', 'articles.author_id')
        ->where('follows.follower_id', '=', $viewerId), 'LEFT')
    ->select('articles.title', 'users.username', 'images.url', 'follows.follower_id')
    ->get();
```

`join()` takes a type of `INNER` (the default), `LEFT` or `RIGHT`.
`joinOn()` builds a compound `ON` clause from the same `Conditions`
methods, bound values included. `joinSub()` and `crossJoin()` are covered
in {ref}`query-builder-reference-joins`.

## Ordering and pagination

```{code-block} php
->orderBy('published_at', 'desc')
->orderByRaw('FIELD(status, ?, ?)', ['pinned', 'published'])
->limit(20)
->offset(40)
```

The direction is `ASC` or `DESC`, case-insensitive. `limit()` and
`offset()` take integers of 0 or more.

### Page numbers: `paginate()`

```{code-block} php
use Kinetis\Http\Attributes\Get;
use Kinetis\Http\Attributes\Query as QueryParameter;
use Kinetis\QueryBuilder\Paginator;

#[Get('/articles')]
public function index(#[QueryParameter] int $page = 1, #[QueryParameter] int $perPage = 20): Paginator
{
    return new Query($this->db)
        ->table('articles')
        ->where('status', '=', 'published')
        ->orderBy('id')
        ->paginate($perPage, $page, ArticleRow::class);
}
```

```{code-block} json
:caption: GET /articles?page=2&perPage=20

{
    "data": [{"id": 21, "...": "..."}],
    "currentPage": 2,
    "perPage": 20,
    "total": 145,
    "lastPage": 8
}
```

`paginate(int $perPage, int $page = 1, ?string $dtoClass = null)` runs a
`count()` for `total` and a limited `get()` for the page. A page past the
last one returns empty `data` with the real `total`.

```{warning}
`paginate()` requires an `orderBy()` or `orderByRaw()` and throws
`QueryBuilderException` without one. Order by a key unique across the
result — a primary key, or your sort column plus one. With ties, a page
boundary inside a run of equal values can repeat or skip rows.
```

### Cursors: `cursorPaginate()`

```{code-block} php
use Kinetis\QueryBuilder\CursorPaginator;

#[Get('/articles')]
public function index(#[QueryParameter] ?string $cursor = null): CursorPaginator
{
    return new Query($this->db)->table('articles')->cursorPaginate(perPage: 20, cursor: $cursor);
}
```

```{code-block} json
:caption: GET /articles, then GET /articles?cursor=145

{"data": ["...", "..."], "nextCursor": "165", "hasMore": true}
```

`cursorPaginate()` orders by its cursor column (`id` unless you pass
`cursorColumn:`), reads only rows past the cursor it is given, and takes
`nextCursor` from the last delivered row. There is no total and no page
number, so rows inserted between requests cannot shift a page.

```{warning}
The cursor column must be unique and strictly increasing — a primary
key, not `created_at`. `cursorPaginate()` owns the ordering, limit and
offset: an existing `orderBy()`, `limit()` or `offset()` above zero throws
`InvalidPaginationException`. A joined query needs a qualified cursor
column and a `cursorAlias:` ({ref}`query-builder-reference-pagination`).
```

Both methods refuse a `perPage` (and `paginate()` a `page`) below 1 with
`InvalidPaginationException`, and neither caps `perPage`. Bound request
values before they reach the query: a rule such as `#[GreaterThan(0)]` on
the `#[Query]` parameter refuses a bad value as a validation failure
(see {doc}`routing-validation`), and clamping keeps `perPage` in range.
`Paginator` and `CursorPaginator` are readonly envelopes whose public
fields encode as the JSON shown above.

### Describing the page item in OpenAPI

`#[PaginatedItem]` names the item class of a response wrapper whose
`data` is the item list — `Paginator`, `CursorPaginator`, or a wrapper of
your own — so the generated schema describes `data` as an array of that
class. Without it, `data` is a bare array. The attribute is descriptive
only; nothing checks the returned items against it.

```{code-block} php
use Kinetis\Http\Attributes\PaginatedItem;

#[Get('/articles')]
#[PaginatedItem(ArticleRow::class)]
public function index(#[QueryParameter] int $page = 1): Paginator
{
    return new Query($this->db)->table('articles')->orderBy('id')->paginate(20, $page, ArticleRow::class);
}
```

## Counting and aggregates

```{code-block} php
$total = new Query($db)->table('articles')->where('status', '=', 'published')->count(); // int
$views = new Query($db)->table('articles')->where('author_id', '=', $id)->sum('views');  // int|float|string|null

$authors = new Query($db)
    ->table('articles')
    ->select('author_id')
    ->selectRaw('COUNT(*) AS articles')
    ->where('status', '=', 'published')
    ->groupBy('author_id')
    ->havingRaw('COUNT(*) >= ?', [5])
    ->get();
```

`count()`, `sum()`, `min()`, `max()` and `avg()` ignore the order, limit
and offset. The last four return the value as the driver delivers it — a
decimal can arrive as a string — or `null` when there are no rows. On a
grouped or `distinct()` query they aggregate the rows the query returns,
so `count()` over the `$authors` query counts authors, not articles
({ref}`query-builder-reference-aggregates`).

## Writing rows

```{code-block} php
new Query($db)->table('tags')->insert(['name' => 'PHP', 'slug' => 'php']);

$id = new Query($db)->table('articles')->insertGetId(['title' => $title, 'slug' => $slug]);
// int|string|null — a string for a MySQL id beyond PHP_INT_MAX

$inserted = new Query($db)->table('favorites')->insertOrIgnore(['user_id' => $userId, 'article_id' => $articleId]);
// int: 1 when the row was written, 0 when a unique key already held it

new Query($db)->table('article_stats')->upsert(
    ['article_id' => $id, 'views' => $views, 'updated_at' => $now],
    uniqueBy: ['article_id'],
    update: ['views', 'updated_at'],
);

$updated = new Query($db)->table('articles')->where('id', '=', $id)->update(['title' => $title]); // int
$counted = new Query($db)->table('articles')->where('id', '=', $id)->increment('views');          // int
$deleted = new Query($db)->table('articles')->where('status', '=', 'spam')->delete();             // int
```

- `insert()` takes one `column => value` row or a list of rows naming the
  same columns in the same order, and runs one statement.
- `insertOrIgnore()` skips a row that conflicts with a unique key; every
  other error, such as a `NOT NULL` violation, still fails the statement.
- `upsert()` inserts each row, and a row that conflicts sets the `$update`
  columns of the existing row instead. On MySQL and MariaDB a conflict on
  any unique key of the table triggers the update, and the affected-row
  count differs from PostgreSQL's
  ({ref}`query-builder-reference-upsert`).
- `update()`, `increment()`, `decrement()` and `delete()` return the
  affected-row count. No write returns rows or DTOs.

```{warning}
`update()`, `increment()`, `decrement()` and `delete()` need at least one
where predicate, and compile only the table and the `WHERE` clause. A
query with no predicate, only empty groups, or any other clause — a join,
an order, a limit, an alias, a lock — throws `QueryBuilderException`
before running, since the statement would otherwise reach more rows than
you narrowed it to. Run a deliberate whole-table or joined mutation as
raw SQL.
```

(query-builder-row-values)=
### Writing objects: `RowValues`

`RowValues::fromObject()` turns an object's public properties into the
`column => value` map every write takes:

```{code-block} php
use Kinetis\QueryBuilder\RowValues;

enum ArticleStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
}

final readonly class CreateArticle
{
    public function __construct(
        public string $title,
        public ArticleStatus $status,
        public ?string $summary = null,
        public ?int $editorId = null,
    ) {}
}

$values = RowValues::fromObject(new CreateArticle('Hello', ArticleStatus::Draft), columns: ['editorId' => 'editor_id']);
// ['title' => 'Hello', 'status' => 'draft', 'summary' => null, 'editor_id' => null]

$id = new Query($db)->table('articles')->insertGetId($values);
```

`fromObject(object $object, array $columns = [], array $except = [])`
reads the initialized public properties, readonly and
asymmetric-visibility ones included:

- `null` is kept and writes `NULL`.
- A backed enum becomes its value. Every other value must already be
  `null`, a `bool`, an `int`, a finite `float` or a `string`; anything
  else, a unit enum included, throws `InvalidArgumentException` naming
  the property.
- `$columns` renames properties; `$except` leaves them out. An unknown
  name, a property both renamed and excluded, and two properties mapping
  to one column all throw.

It does no case conversion, date formatting or nesting. Hash a password
or format a timestamp before extracting. An object with nothing to write
yields `[]`, which the writes refuse.

(query-builder-partial-updates)=
### Partial updates

`fromObject()` writes every public property it reads, so a partial update
chooses its columns before extracting. A Kinetis request DTO that marks
omitted members with `Kinetis\Validation\Absent` (see
{doc}`routing-validation`) needs that step: `Absent` is a framework
request concept the query builder does not recognize, and an
`Absent::Value` property is a unit enum, which throws. Derive the omitted
properties and pass them as `$except`:

```{code-block} php
use Kinetis\QueryBuilder\RowValues;
use Kinetis\Validation\Absent;

final readonly class UpdateArticle
{
    public function __construct(
        public string|Absent $title = Absent::Value,
        public ArticleStatus|Absent $status = Absent::Value,
        public string|null|Absent $summary = Absent::Value,
    ) {}
}

$update = new UpdateArticle(status: ArticleStatus::Published, summary: null);

$omitted = array_keys(array_filter(
    get_object_vars($update),
    static fn (mixed $value): bool => $value === Absent::Value,
));

$values = RowValues::fromObject($update, except: $omitted);
// ['status' => 'published', 'summary' => null]

if ($values !== []) {
    new Query($db)->table('articles')->where('id', '=', $id)->update($values);
}
```

The explicit `null` stays in the row and clears the column. A property
left out through `$except` cannot also be renamed through `$columns`.
When the request sent nothing, `$values` is `[]`: skip the statement
rather than handing `update()` a map it refuses.

### Writing and then reading back

Writes return counts and ids, not rows. When the caller needs the stored
row, read it in the same transaction:

```{code-block} php
$article = $transactions->transaction($db, function (SqlTransaction $tx) use ($create): ArticleRow {
    $id = new Query($tx)->table('articles')->insertGetId(RowValues::fromObject($create));

    $row = new Query($tx)->table('articles')->where('id', '=', $id)->first(ArticleRow::class);

    if ($row === null) {
        throw new RuntimeException('The inserted article was not found.');
    }

    return $row;
});
```

For an update, select by a stable key such as the id, not by a predicate
the update itself can make false. To return several updated or deleted
rows, lock them and read their keys before writing.

## Transactions and row locks

Pass the transaction to `Query` to run statements inside it. Type the
callback against the generic `SqlTransaction`: `Query` detects the
dialect from the concrete transaction object it receives, not from the
declared parameter type.

```{code-block} php
use Kinetis\Persistence\Contract\SqlTransaction;
use Kinetis\Persistence\TransactionGuard;
use Kinetis\QueryBuilder\LockWait;

// $transactions is the injected TransactionGuard, $db the injected MysqlLink.
$transactions->transaction($db, function (SqlTransaction $tx) use ($accountId, $amount): void {
    $balance = new Query($tx)
        ->table('accounts')
        ->where('id', '=', $accountId)
        ->lockForUpdate()
        ->value('balance');

    if ($balance < $amount) {
        throw new InsufficientFunds();
    }

    new Query($tx)->table('accounts')->where('id', '=', $accountId)->decrement('balance', $amount);
});
```

The decrement is committed once `transaction()` returns, not when
`decrement()` does; {doc}`persistence` covers commit, rollback and
unknown outcomes.

`lockForUpdate()` locks the selected rows until the transaction ends. Its
wait mode decides what happens when another transaction holds one:

```{code-block} php
->lockForUpdate()                      // wait, up to the server's lock timeout
->lockForUpdate(LockWait::NoWait)      // fail immediately
->lockForUpdate(LockWait::SkipLocked)  // leave locked rows out of the result
```

`SkipLocked` suits a work queue, where each worker claims the rows no
other worker holds:

```{code-block} php
$jobs = new Query($tx)
    ->table('jobs')
    ->where('state', '=', 'queued')
    ->orderBy('id')
    ->limit(10)
    ->lockForUpdate(LockWait::SkipLocked)
    ->get();
```

`lockForShare()` lets other transactions read and share-lock the rows but
not change them; it always waits.

```{warning}
A lock needs a `Query` built on an active transaction, and throws
`QueryBuilderException` otherwise. It is admitted on `get()`, `first()`,
`value()` and `pluck()` over one table or inner joins, with predicates,
ordering, limit and offset; every other combination is refused before
running ({ref}`query-builder-reference-locks`). A `NoWait` conflict on
MariaDB is error 1205, which ends the transaction.
```

## Raw SQL

For SQL beyond the shared surface, call the connection directly:

```{code-block} php
$result = $db->execute('SELECT id FROM articles WHERE MATCH(title) AGAINST (?)', [$term]);
```

Inside a query, `selectRaw()`, `whereRaw()`, `groupByRaw()`, `havingRaw()`
and `orderByRaw()` take a fragment and its parameters. Parameters bind
where their fragment appears in the SQL, whatever order you called the
methods in.

```{danger}
A raw fragment is inserted as written. Pass every value through
`$params`; concatenating user input into the fragment reopens SQL
injection.
```

(query-builder-cookbook)=
## Repository cookbook

One repository combining the pieces above: an injected connection and
`TransactionGuard`, a transaction callback typed against the shared
`SqlTransaction`, a unique-key conflict handled without a race, a
relationship flag, and a walk over a large table.

```{code-block} php
use Kinetis\Persistence\Contract\MysqlLink;
use Kinetis\Persistence\Contract\SqlTransaction;
use Kinetis\Persistence\Exception\QueryException;
use Kinetis\Persistence\TransactionGuard;
use Kinetis\QueryBuilder\Query;
use RuntimeException;

// Application-owned: the row DTO and the conflict exception.
final readonly class UserCardRow
{
    public function __construct(
        public int $id,
        public string $username,
        public bool $following,
    ) {}
}

final class AccountExists extends RuntimeException
{
}

final readonly class UserRepository
{
    public function __construct(
        private MysqlLink $db,
        private TransactionGuard $transactions,
    ) {}

    public function register(string $username, string $email): int
    {
        try {
            return $this->transactions->transaction(
                $this->db,
                static function (SqlTransaction $tx) use ($username, $email): int {
                    $id = (int) new Query($tx)->table('users')->insertGetId(['username' => $username, 'email' => $email]);
                    new Query($tx)->table('user_settings')->insert(['user_id' => $id, 'newsletter' => false]);

                    return $id;
                },
            );
        } catch (QueryException $e) {
            if (!$e->isUniqueViolation()) {
                throw $e;
            }

            throw new AccountExists('An account with that username or email already exists.', previous: $e);
        }
    }

    public function find(string $username, int $viewerId): ?UserCardRow
    {
        $follows = new Query($this->db)->table('follows', as: 'f')
            ->whereColumn('f.followed_id', '=', 'u.id')
            ->where('f.follower_id', '=', $viewerId);

        return new Query($this->db)->table('users', as: 'u')
            ->select('u.id', 'u.username')
            ->selectExists($follows, 'following')
            ->where('u.username', '=', $username)
            ->first(UserCardRow::class);
    }

    /** @param callable(list<array<string, mixed>>): void $handle */
    public function eachBatch(callable $handle, int $batchSize = 500): void
    {
        $cursor = null;

        do {
            $page = new Query($this->db)->table('users')
                ->select('id', 'email')
                ->cursorPaginate(perPage: $batchSize, cursor: $cursor);

            $handle($page->data);
            $cursor = $page->nextCursor;
        } while ($page->hasMore);
    }
}
```

- **Wiring.** `MysqlLink` is the connection `kinetis/database-bridge`
  binds from `DB_CONNECTION` (see {doc}`persistence`), and
  `TransactionGuard` is request-scoped, so every request gets its own. On
  PostgreSQL, inject `PostgresLink` instead; `register()`'s callback
  stays typed `SqlTransaction` either way.
- **Conflicts.** `register()` does not read for an existing username
  first: another request can insert the same one between that read and
  this write. The unique keys decide, `isUniqueViolation()` recognizes
  the answer on every driver, and the guard has already rolled the
  transaction back when the catch runs — see {doc}`persistence`'s
  "Unique violations".
- **Flags.** `selectExists()` selects `1` or `0`, which
  `UserCardRow::$following` receives as a `bool`
  ({ref}`query-builder-reference-subqueries`).
- **Large tables.** A result is buffered whole, so `eachBatch()` reads
  bounded pages with `cursorPaginate()` rather than selecting the table
  at once. Each page is its own statement: a row inserted meanwhile
  appears on a later page, and no row is delivered twice.

## See also

- {doc}`appendix-query-builder` — the complete builder contract.
- {doc}`persistence` — connections, transactions and unknown outcomes.
- {doc}`routing-validation` — request DTOs, validation, and `Absent`.
- {doc}`appendix-packages` — the package's API catalogue.
