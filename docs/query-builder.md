# Query Builder

````{note}
Not part of core. Install it separately:

```{code-block} sh
composer require kinetis/query-builder
```
````

A thin, parameterized SQL query builder over {doc}`persistence`'s
drivers. It compiles the SQL that MySQL 8.4, MariaDB 11.4 and
PostgreSQL 16 share, runs it on the connection or transaction you pass
in, and maps result rows into typed DTOs through
{doc}`routing-validation`'s `Hydrator`. Anything outside that shared
surface stays available as raw SQL on the same connection.

It is not an ORM: no relationships, no identity map, no change tracking,
no `save()` on a model, no schema builder.

## Quick start

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

```{warning}
Values are always bound as parameters. Identifiers (table and column
names) are quoted but not validated, and raw SQL fragments are inserted
as written — never build either from user input.
```

## Creating a query

`new Query($link)` takes a connection or an open transaction. The link's
type picks the SQL spelling: a `MysqlLink` compiles for MySQL and
MariaDB, a `PostgresLink` for PostgreSQL.

```{code-block} php
new Query($db);  // the registered connection, see persistence
new Query($tx);  // an open transaction, see "Transactions and row locks"

use Kinetis\Persistence\SqlConnectionFactory;

$reporting = SqlConnectionFactory::fromConfig($config, 'reporting');
$totals = new Query($reporting)->table('daily_totals')->get();
```

Builder methods (`table()`, `where()`, `join()`, ...) add to the query
and return the same instance. Terminal methods (`get()`, `count()`,
`insert()`, `update()`, ...) run SQL.

```{warning}
One `Query` is one statement: nothing resets between calls. Create a
fresh `new Query($link)` for every statement. The read terminals that
add a limit, order or projection (`first()`, `value()`, `paginate()`,
`cursorPaginate()`) apply it to a copy, so the builder you hold is
unchanged afterwards.
```

## Fetching rows

### `get()` and `first()`

```{code-block} php
$rows = new Query($db)->table('articles')->where('status', '=', 'published')->get();
// list<array<string, mixed>>

$articles = new Query($db)->table('articles')->where('status', '=', 'published')->get(ArticleRow::class);
// list<ArticleRow>

$article = new Query($db)->table('articles')->where('slug', '=', $slug)->first(ArticleRow::class);
// ArticleRow|null
```

Pass a DTO class and each row is hydrated with `Hydrator::hydrate()`
under `InputSource::Native`, constraints included. Columns the DTO does
not declare are ignored, and a driver may return an integer as an `int`
or as its decimal string — both bind. See {doc}`routing-validation`'s
"Scalar type checking".

### `value()`, `pluck()` and `exists()`

```{code-block} php
$title = new Query($db)->table('articles')->where('id', '=', $id)->value('title');
// mixed: the column of the first row, or null when there is no row

$slugs = new Query($db)->table('articles')->select('slug')->orderBy('slug')->pluck('slug');
// list<mixed>

$taken = new Query($db)->table('articles')->where('slug', '=', $slug)->exists();
// bool
```

`value()` and `pluck()` read the column from each row by its result
name and leave the projection as you built it. A qualified column
(`articles.slug`) arrives under its last segment, so pass `'slug'`. A
name missing from the row throws `QueryBuilderException`.

## Selecting columns

```{code-block} php
$rows = new Query($db)
    ->table('articles', as: 'a')
    ->join('users', 'u.id', '=', 'a.author_id', as: 'u')
    ->select('a.id', 'a.title', 'u.username', 'a.*')
    ->get();
```

- `select()` replaces the column list; the default is `*`. Each
  dot-separated segment is quoted, and a trailing `.*` stays a wildcard.
- `table()`'s and `join()`'s `as:` quote a table alias.
- `select()` takes column names only. Name a computed or renamed column
  with `selectRaw()`.
- `distinct()` compiles `SELECT DISTINCT`.

```{code-block} php
$rows = new Query($db)
    ->table('articles')
    ->select('id')
    ->selectRaw('LENGTH(body) > ? AS is_long', [2000])
    ->get();
```

`selectRaw()` appends an expression, binding its `?` placeholders from
`$params`. Once `selectRaw()` or `selectSub()` is used without
`select()`, the default `*` is dropped.

## Filtering

### Comparisons

```{code-block} php
$rows = new Query($db)
    ->table('articles')
    ->where('status', '=', 'published')
    ->where('views', '>=', 100)
    ->orWhere('featured', '=', true)
    ->get();
// WHERE `status` = ? AND `views` >= ? OR `featured` = ?
```

The operator is one of `=`, `!=`, `<>`, `<`, `<=`, `>`, `>=`, `LIKE`,
`NOT LIKE`, case-insensitive. Predicates join with `AND` unless you use
the `or...` form; use a group for any mix of the two.

### Null

```{code-block} php
->where('deleted_at', '=', null)   // `deleted_at` IS NULL
->where('deleted_at', '!=', null)  // `deleted_at` IS NOT NULL
->where('score', '>', null)        // throws InvalidArgumentException
```

`column = NULL` is never true in SQL, so a null compiles to `IS NULL` or
`IS NOT NULL`. Every other operator against null is refused. Inserted
and updated null values bind normally.

### `IN` and `BETWEEN`

```{code-block} php
->whereIn('status', ['draft', 'review'])
->whereNotIn('author_id', $blockedIds)
->whereBetween('published_at', '2026-01-01', '2026-06-30')
->orWhereNotBetween('views', 10, 20)
```

An empty `whereIn()` list matches no row (`1 = 0`), and an empty
`whereNotIn()` list matches every row (`1 = 1`). A null inside a list,
or as a `BETWEEN` bound, is refused. Both `IN` forms also take a
subquery — see [Subqueries](#subqueries).

### Grouping `AND` and `OR`

```{code-block} php
use Kinetis\QueryBuilder\Conditions;

$rows = new Query($db)
    ->table('articles')
    ->where('status', '=', 'published')
    ->whereGroup(fn (Conditions $group) => $group
        ->where('author_id', '=', $authorId)
        ->orWhere('featured', '=', true))
    ->get();
// WHERE `status` = ? AND (`author_id` = ? OR `featured` = ?)
```

`whereGroup()` and `orWhereGroup()` parenthesize what the callback adds.
The callback receives a `Conditions` object with the filtering methods
on this page and nothing else, and groups nest. A group that adds
nothing compiles to nothing.

### Comparing columns

```{code-block} php
->whereColumn('updated_at', '>', 'published_at')
->orWhereColumn('author_id', '=', 'editor_id')
```

### Raw predicates

```{code-block} php
->whereRaw('LOWER(title) LIKE ?', ['%' . strtolower($term) . '%'])
->whereRaw('YEAR(published_at) = ?', [2026], 'OR')
```

`whereRaw()` refuses an empty fragment. See [Raw SQL](#raw-sql).

## Joins

```{code-block} php
$rows = new Query($db)
    ->table('articles')
    ->join('users', 'users.id', '=', 'articles.author_id')
    ->leftJoin('images', 'images.article_id', '=', 'articles.id')
    ->select('articles.title', 'users.username', 'images.url')
    ->get();
```

`join()` takes a type of `INNER` (the default), `LEFT` or `RIGHT`.

A compound `ON` clause uses the same `Conditions` methods as a group,
bound values included:

```{code-block} php
$rows = new Query($db)
    ->table('articles')
    ->joinOn('follows', fn (Conditions $on) => $on
        ->whereColumn('follows.followee_id', '=', 'articles.author_id')
        ->where('follows.follower_id', '=', $viewerId), 'LEFT')
    ->select('articles.id', 'follows.follower_id')
    ->get();
```

`crossJoin('sizes')` joins every row with every row. `joinSub()` joins a
subquery under an alias:

```{code-block} php
$counts = new Query($db)
    ->table('comments')
    ->select('article_id')
    ->selectRaw('COUNT(*) AS total')
    ->groupBy('article_id');

$rows = new Query($db)
    ->table('articles')
    ->joinSub($counts, 'c', fn (Conditions $on) => $on->whereColumn('c.article_id', '=', 'articles.id'))
    ->select('articles.title', 'c.total')
    ->get();
```

## Subqueries

Pass a `Query` wherever a subquery is accepted. It is compiled at the
moment you pass it, so changing it afterwards does not change the outer
query. It must be built on the same kind of connection, and it cannot
carry `with()` or a row lock.

### `EXISTS`

```{code-block} php
$favorited = new Query($db)
    ->table('favorites')
    ->whereColumn('favorites.article_id', '=', 'articles.id')
    ->where('favorites.user_id', '=', $userId);

$rows = new Query($db)->table('articles')->whereExists($favorited)->get();
```

`whereExists()`, `orWhereExists()`, `whereNotExists()` and
`orWhereNotExists()` take a subquery that may refer to the outer
query's tables. The same predicates work in `update()` and `delete()`.

### `IN`

```{code-block} php
$followed = new Query($db)
    ->table('follows')
    ->select('followee_id')
    ->where('follower_id', '=', $userId);

$feed = new Query($db)->table('articles')->whereIn('author_id', $followed)->get();
```

On MySQL and MariaDB, an `IN` subquery carrying `limit()` or `offset()`
throws `QueryBuilderException`: both servers reject that statement.
Select from the limited query with `fromSub()` instead, which all three
servers accept:

```{code-block} php
$latest = new Query($db)->table('articles')->select('id')->orderBy('id', 'desc')->limit(10);

$comments = new Query($db)
    ->table('comments')
    ->whereIn('article_id', new Query($db)->fromSub($latest, 'latest')->select('latest.id'))
    ->get();
```

### In the select list and the `FROM` clause

```{code-block} php
$favoriteCount = new Query($db)
    ->table('favorites')
    ->selectRaw('COUNT(*)')
    ->whereColumn('favorites.article_id', '=', 'articles.id');

$rows = new Query($db)
    ->table('articles')
    ->select('articles.id', 'articles.title')
    ->selectSub($favoriteCount, 'favorites_count')
    ->get();

$busyAuthors = new Query($db)
    ->fromSub(new Query($db)->table('articles')->select('author_id')->where('status', '=', 'published'), 'p')
    ->select('p.author_id')
    ->distinct()
    ->get();
```

## Ordering, limits and pagination

```{code-block} php
->orderBy('published_at', 'desc')
->orderByRaw('FIELD(status, ?, ?)', ['pinned', 'published'])
->limit(20)
->offset(40)
```

The direction is `ASC` or `DESC`, case-insensitive. `limit()` and
`offset()` take integers of 0 or more; `offset()` works without
`limit()`.

### Page numbers: `paginate()`

```{code-block} php
use Kinetis\Http\Attributes\Get;
use Kinetis\Http\Attributes\Query as QueryParameter;
use Kinetis\Http\Pagination\Paginator;

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

`paginate(int $perPage, int $page = 1, ?string $dtoClass = null)` runs
`count()` for `total` and a limited `get()` for the page. A page past
the last one returns empty `data` with the real `total`.

```{warning}
`paginate()` requires an `orderBy()`/`orderByRaw()` and throws
`QueryBuilderException` without one. Order by a key unique across the
result — a primary key, or your sort column plus one. With ties, a page
boundary inside a run of equal values can repeat or skip rows.
```

### Cursors: `cursorPaginate()`

```{code-block} php
use Kinetis\Http\Pagination\CursorPaginator;

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

`cursorPaginate(int $perPage, ?string $cursor, string $cursorColumn = 'id', ?string $dtoClass = null, ?string $cursorAlias = null)`
orders by `$cursorColumn`, filters `$cursorColumn > $cursor` once a
cursor is given, and reads `nextCursor` from the last delivered row in
the same query. There is no total and no page number, so rows inserted
between requests cannot shift a page.

```{warning}
`$cursorColumn` must be unique and strictly increasing — a primary key,
not `created_at`. `cursorPaginate()` owns the ordering, limit and offset:
an existing `orderBy()`, `limit()` or `offset()` above zero throws
`InvalidPaginationException`.
```

The cursor filter wraps your own predicates as
`(existing predicate) AND id > ?`, so an `orWhere()` cannot escape it. A
projection that omits the cursor column still works: the column is
selected, read, and removed from every row before hydration.

On a joined query, pass a qualified cursor column and name an alias for
it. Both servers return `orders.id` under the bare key `id`, which a
joined table's `id` would overwrite:

```{code-block} php
return new Query($this->db)
    ->table('orders')
    ->join('customers', 'orders.customer_id', '=', 'customers.id')
    ->select('orders.total', 'customers.name')
    ->cursorPaginate(perPage: 20, cursor: $cursor, cursorColumn: 'orders.id', cursorAlias: 'order_cursor');
```

A qualified column without an alias, or an alias equal to a column you
listed in `select()`, throws `InvalidPaginationException`. An alias
that a wildcard's columns already use cannot be detected: that column
is replaced in the returned rows, so pick a name nothing in the
projection uses.

Both methods refuse a `perPage` (and `paginate()` a `page`) below 1 with
`InvalidPaginationException`, which reaches the client as a `400`.
Neither caps `perPage`; clamp request values in your controller.

### Describing the page item in OpenAPI

`Paginator` and `CursorPaginator` hold any item type, so the generated
schema describes `data` as bare objects unless the route names the item:

```{code-block} php
use Kinetis\Http\Attributes\PaginatedItem;

#[Get('/articles')]
#[PaginatedItem(ArticleRow::class)]
public function index(#[QueryParameter] int $page = 1): Paginator
{
    return new Query($this->db)->table('articles')->orderBy('id')->paginate(20, $page, ArticleRow::class);
}
```

The attribute is descriptive only; nothing checks the returned items
against it.

## Grouping and aggregates

```{code-block} php
$authors = new Query($db)
    ->table('articles')
    ->select('author_id')
    ->selectRaw('COUNT(*) AS articles')
    ->where('status', '=', 'published')
    ->groupBy('author_id')
    ->havingRaw('COUNT(*) >= ?', [5])
    ->get();
```

- `groupBy(string ...$columns)` and `groupByRaw($sql, $params)`.
- `having()`/`orHaving()` compare a grouped column with the same
  operators and null handling as `where()`.
- `havingRaw($sql, $params)` compares an aggregate.

### Counting and aggregate values

```{code-block} php
$total = new Query($db)->table('articles')->where('status', '=', 'published')->count();  // int
$views = new Query($db)->table('articles')->where('author_id', '=', $id)->sum('views');   // int|float|string|null
$first = new Query($db)->table('articles')->min('published_at');                          // int|float|string|null
```

`count()`, `sum()`, `min()`, `max()` and `avg()` ignore the order, limit
and offset. `sum()`, `min()`, `max()` and `avg()` return the value as
the driver delivers it, where a decimal can arrive as a string, or
`null` when there are no rows.

On a query with `distinct()`, `groupBy()`, a `having` clause or a set
operation, they aggregate the rows the query returns: `count()` on the
grouped query above counts authors, not articles. On any other query
they aggregate the filtered and joined rows directly, and the select
list plays no part.

## Set operations and CTEs

### `union()`, `intersect()` and `except()`

```{code-block} php
$pinned = new Query($db)->table('articles')->select('id', 'title')->where('pinned', '=', true);
$recent = new Query($db)->table('articles')->select('id', 'title')->orderBy('published_at', 'desc')->limit(5);

$front = $pinned
    ->union($recent)
    ->orderBy('title')
    ->limit(10)
    ->get();
// (SELECT ... WHERE `pinned` = ?) UNION (SELECT ... ORDER BY `published_at` DESC LIMIT 5) ORDER BY `title` ASC LIMIT 10
```

Each method takes `bool $all = false` for `UNION ALL`, `INTERSECT ALL`
and `EXCEPT ALL`. On the query you call them on, `orderBy()`, `limit()`
and `offset()` apply to the combined result; an operand's own ordering
and limit stay inside its parentheses. Operations apply in call order:
`$a->union($b)->intersect($c)` is `(a ∪ b) ∩ c`. A combined query can
itself be an operand.

### `with()`

```{code-block} php
$popular = new Query($db)->table('favorites')->select('article_id')->groupBy('article_id')->havingRaw('COUNT(*) > ?', [100]);

$rows = new Query($db)
    ->with('popular', $popular)
    ->table('articles')
    ->whereIn('id', new Query($db)->table('popular')->select('article_id'))
    ->get();
// WITH `popular` AS (SELECT ...) SELECT * FROM `articles` WHERE `id` IN (SELECT `article_id` FROM `popular`)
```

`with(string $name, Query $query, array $columns = [])` defines a common
table expression that the query, and its subqueries, select from by
name. `$columns` names its result columns.

### `withRecursive()`

```{code-block} php
$root = new Query($db)->table('categories')->select('id', 'parent_id')->where('id', '=', $categoryId);
$children = new Query($db)
    ->table('categories')
    ->select('categories.id', 'categories.parent_id')
    ->join('tree', 'tree.id', '=', 'categories.parent_id');

$descendantIds = new Query($db)
    ->withRecursive('tree', $root->union($children, all: true), ['id', 'parent_id'])
    ->table('tree')
    ->pluck('id');
```

The recursive query is a `union()` of a starting query and a query that
joins the CTE's own name. One recursive CTE makes the whole clause
`WITH RECURSIVE`.

## Inserts

### `insert()`

```{code-block} php
new Query($db)->table('tags')->insert(['name' => 'PHP', 'slug' => 'php']);

new Query($db)->table('tags')->insert([
    ['name' => 'PHP', 'slug' => 'php'],
    ['name' => 'SQL', 'slug' => 'sql'],
]);
```

`insert()` takes one `column => value` row or a list of rows and runs one
statement. Every row of a batch must name the same columns in the same
order. A batch binding more than 65,535 values throws
`InvalidArgumentException`; split it yourself, knowing each call is its
own statement.

### `insertGetId()`

```{code-block} php
$id = new Query($db)->table('articles')->insertGetId(['title' => $title, 'slug' => $slug]);
// int|string|null — a string for a MySQL id beyond PHP_INT_MAX
```

`insertGetId(array $values, string $primaryKey = 'id')` inserts one row
and returns its generated key.

### `insertUsing()`

```{code-block} php
$inserted = new Query($db)->table('notifications')->insertUsing(
    ['user_id', 'article_id'],
    new Query($db)->table('follows')
        ->join('articles', 'articles.author_id', '=', 'follows.followee_id')
        ->select('follows.follower_id', 'articles.id')
        ->where('articles.id', '=', $articleId),
);
// int: rows inserted
```

The select may carry its own `with()`.

### `insertOrIgnore()`

```{code-block} php
$inserted = new Query($db)->table('favorites')->insertOrIgnore(['user_id' => $userId, 'article_id' => $articleId]);
// int: 1 when the row was written, 0 when a unique key already held it
```

Takes a row or a batch. A row that conflicts with a unique key is
skipped; every other error, such as a `NOT NULL` violation, still fails
the statement.

### `upsert()`

```{code-block} php
new Query($db)->table('article_stats')->upsert(
    ['article_id' => $id, 'views' => $views, 'updated_at' => $now],
    uniqueBy: ['article_id'],
    update: ['views', 'updated_at'],
);
// int: the server's affected-row count
```

`upsert(array $values, array $uniqueBy, array $update)` inserts each row;
a row that conflicts instead sets the `$update` columns of the existing
row to the values it tried to insert. MySQL and MariaDB resolve a
conflict on any unique key and count rows differently — see
[Upsert on MySQL and MariaDB](#upsert-on-mysql-and-mariadb).

Writes do not return rows or DTOs. Fetching what you wrote is a second
statement — see [Writing objects](#query-builder-row-values).

## Updates, counters and deletes

```{code-block} php
$updated = new Query($db)->table('articles')->where('id', '=', $id)->update(['title' => $title]);  // int
$counted = new Query($db)->table('articles')->where('id', '=', $id)->increment('views');           // int
$sold = new Query($db)->table('products')->where('id', '=', $id)->decrement('stock', 2, ['sold_at' => $now]);
$deleted = new Query($db)->table('articles')->where('status', '=', 'spam')->delete();              // int
```

Each returns the affected-row count. `increment()` and `decrement()`
take an `int|float` amount (1 by default) and an optional map of further
`column => value` assignments. Correlated predicates work as in a
select:

```{code-block} php
new Query($db)
    ->table('articles')
    ->whereNotExists(new Query($db)->table('comments')->whereColumn('comments.article_id', '=', 'articles.id'))
    ->where('published_at', '<', $cutoff)
    ->delete();
```

```{warning}
`update()`, `increment()`, `decrement()` and `delete()` need at least one
where predicate, and compile only the table and the `WHERE` clause. A
query with no predicate, only empty groups, or any other clause — a
join, an order, a limit, an alias, a lock — throws
`QueryBuilderException` before running, since the statement would
otherwise reach more rows than you narrowed it to. Run a deliberate
whole-table or joined mutation as raw SQL.
```

## Transactions and row locks

Pass the transaction to `Query` to run statements inside it:

```{code-block} php
use Kinetis\Persistence\TransactionGuard;
use Kinetis\QueryBuilder\LockWait;

// $transactions is the injected TransactionGuard.
$transactions->transaction($db, function ($tx) use ($accountId, $amount): void {
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

`lockForUpdate()` locks the selected rows until the transaction ends.
Its wait mode decides what happens when another transaction holds one:

```{code-block} php
->lockForUpdate()                      // wait, up to the server's lock timeout
->lockForUpdate(LockWait::NoWait)      // fail immediately
->lockForUpdate(LockWait::SkipLocked)  // leave locked rows out of the result
```

`SkipLocked` suits a work queue: each worker claims the rows no other
worker holds.

```{code-block} php
$jobs = new Query($tx)
    ->table('jobs')
    ->where('state', '=', 'queued')
    ->orderBy('id')
    ->limit(10)
    ->lockForUpdate(LockWait::SkipLocked)
    ->get();
```

`lockForShare()` lets other transactions read and share-lock the rows
but not change them; it always waits.

```{warning}
A lock needs a `Query` built on an active transaction, and throws
`QueryBuilderException` otherwise. It is admitted on `get()`, `first()`,
`value()` and `pluck()` over one table or inner joins, with predicates,
ordering, limit and offset. Every other combination is refused before
running — see [Locks](#locks).
```

See {doc}`persistence` for `TransactionGuard`'s commit and rollback
behavior.

(query-builder-row-values)=
## Writing objects: `RowValues`

`RowValues::fromObject()` turns an object's public properties into the
`column => value` map every write takes:

```{code-block} php
use Kinetis\QueryBuilder\RowValues;
use Kinetis\Validation\Absent;

enum ArticleStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
}

final readonly class UpdateArticle
{
    public function __construct(
        public string|Absent $title = Absent::Value,
        public ArticleStatus|Absent $status = Absent::Value,
        public string|null|Absent $summary = Absent::Value,
        public int|Absent $editorId = Absent::Value,
    ) {}
}

$values = RowValues::fromObject(new UpdateArticle(status: ArticleStatus::Published, summary: null), columns: ['editorId' => 'editor_id']);
// ['status' => 'published', 'summary' => null]

new Query($db)->table('articles')->where('id', '=', $id)->update($values);
```

`fromObject(object $object, array $columns = [], array $except = [])`
reads the initialized public properties, readonly and
asymmetric-visibility ones included:

- `Absent::Value` is left out, so a partial update writes only what was
  sent; `null` is kept and writes `NULL`.
- A backed enum becomes its value. Every other value must already be
  `null`, a `bool`, an `int`, a finite `float` or a `string`; anything
  else throws `InvalidArgumentException` naming the property.
- `$columns` renames properties; `$except` leaves them out. An unknown
  name, a property both renamed and excluded, and two properties
  mapping to one column all throw.

It does no case conversion, date formatting or nesting. Hash a password
or format a timestamp before extracting. An object with nothing to write
yields `[]`, which the writes refuse.

### Writing and then reading back

Writes return counts and ids, not rows. When the caller needs the
stored row, read it in the same transaction:

```{code-block} php
$article = $transactions->transaction($db, function ($tx) use ($create): ArticleRow {
    $id = new Query($tx)->table('articles')->insertGetId(RowValues::fromObject($create));

    $row = new Query($tx)->table('articles')->where('id', '=', $id)->first(ArticleRow::class);

    if ($row === null) {
        throw new RuntimeException('The inserted article was not found.');
    }

    return $row;
});
```

For an update, select by a stable key such as the id, not by a
predicate the update itself can make false. To return several updated
or deleted rows, lock them and read their keys before writing.

## Raw SQL

For SQL beyond the shared surface, call the connection directly:

```{code-block} php
$result = $db->execute('SELECT id FROM articles WHERE MATCH(title) AGAINST (?)', [$term]);
```

Inside a query, `selectRaw()`, `whereRaw()`, `groupByRaw()`,
`havingRaw()` and `orderByRaw()` take a fragment and its parameters:

```{code-block} php
->selectRaw('COUNT(*) AS total')
->whereRaw('YEAR(published_at) = ?', [2026])
->orderByRaw('FIELD(status, ?, ?)', ['pinned', 'published'])
```

Parameters bind where their fragment appears in the SQL, whatever order
you called the methods in.

```{danger}
A raw fragment is inserted as written. Pass every value through
`$params`; concatenating user input into the fragment reopens SQL
injection.
```

## Portability and behavior reference

### Dialect spellings

| Feature | MySQL 8.4 and MariaDB 11.4 | PostgreSQL 16 |
| --- | --- | --- |
| Identifier quoting | `` `name` `` | `"name"` |
| `offset()` without `limit()` | `LIMIT 18446744073709551615 OFFSET n` | `OFFSET n` |
| `lockForShare()` | `LOCK IN SHARE MODE` | `FOR SHARE` |
| `insertOrIgnore()` | `ON DUPLICATE KEY UPDATE first_column = first_column` | `ON CONFLICT DO NOTHING` |
| `upsert()` | `ON DUPLICATE KEY UPDATE col = VALUES(col)` | `ON CONFLICT (unique columns) DO UPDATE SET col = EXCLUDED.col` |
| `insertGetId()` | the driver's last insert id | `RETURNING` the key |
| `IN` subquery with `limit()`/`offset()` | refused | compiled |

Everything else compiles identically. `toSelectSql()`,
`toUpdateSql($values)` and `toDeleteSql()` return the SQL and bindings
without running them.

### Upsert on MySQL and MariaDB

- The shared spelling is `VALUES(col)`. MariaDB has no `INSERT ... AS`
  row alias, and MySQL 8.4 runs `VALUES(col)` with deprecation warning
  1287.
- A conflict on any unique key of the table triggers the update;
  `$uniqueBy` is validated but does not reach the SQL. PostgreSQL
  updates only on a conflict with exactly `$uniqueBy`'s constraint.
- The affected-row count is 1 per inserted row, 2 per updated row and 0
  per row left unchanged. PostgreSQL counts 1 per row.
- `insertOrIgnore()` assigns a column to itself instead of using
  `INSERT IGNORE`, which also turns other errors into warnings and
  writes the row.

### Counting

A `distinct()`, grouped, `having` or set-operation query is counted as
`SELECT COUNT(*) FROM (the query) AS aggregate_source`, keeping every
binding the rows depend on. MySQL and MariaDB reject that derived table
when two selected columns share an output name (`a.id` and `b.id`):
select them under distinct names.

### Locks

A lock is admitted on a select from one table or inner joins, with where
predicates, ordering, limit and offset. A locked query is refused with:

- `distinct()`, `groupBy()`, a `having` clause, a set operation, or a
  `LEFT`/`RIGHT` join, which PostgreSQL rejects with a lock;
- `fromSub()`, `joinSub()`, `crossJoin()` or `with()`;
- `count()`, the aggregates, `exists()`, `paginate()` and
  `cursorPaginate()`;
- use as a subquery, operand or `insertUsing()` source, and on any write.

Run any other locking read as raw SQL inside the transaction. A `NoWait`
conflict throws the server's error as a `QueryException`; on MariaDB it
is error 1205, which the MySQL-family drivers treat as ending the
transaction (see {doc}`persistence`).

### What each write refuses

| Write | Refuses |
| --- | --- |
| `update()`, `increment()`, `decrement()`, `delete()` | no where predicate; `with()`, a table alias, `fromSub()`, `distinct()`, `select()`/`selectRaw()`/`selectSub()`, any join, grouping, `having`, set operations, ordering, `limit()`, `offset()`, a lock |
| `insert()`, `insertGetId()`, `insertUsing()`, `insertOrIgnore()`, `upsert()` | all of the above, and any where predicate |

`increment()` also refuses assigning its own column through `$extra`.
`upsert()` refuses an empty `$uniqueBy` or `$update` and a column in
either that is not inserted.

### Subqueries

- A subquery is compiled when passed and must come from the same kind
  of connection. `with()` belongs on the outermost query, where
  subqueries can select from it by name.
- MySQL 8.4 rejects an `UPDATE` or `DELETE` whose subquery reads the
  table being changed (error 1093); MariaDB and PostgreSQL accept it.
- `NOT IN` against a subquery that returns a `NULL` matches no row, as
  SQL defines it.

### How values reach the database

On the native MySQL and PostgreSQL drivers, a statement whose every
value is an `int` or `bool` is sent with those values written as
literals; any `string`, `float` or `null` makes the whole statement
bind. On the PDO drivers, which carry
`Kinetis\Persistence\Contract\PrefersPreparedStatements`, every value
binds. Any raw fragment — including one inside a subquery, CTE, operand
or join — makes the whole statement bind, since raw text may contain a
`?` that is not a placeholder.

### Allow-listed keywords

Operators (`=`, `!=`, `<>`, `<`, `<=`, `>`, `>=`, `LIKE`, `NOT LIKE`),
order directions (`ASC`, `DESC`), join types (`INNER`, `LEFT`, `RIGHT`)
and the `$boolean` argument (`AND`, `OR`) are checked when set and throw
`InvalidArgumentException` for anything else. They cannot be bound as
parameters, so a sortable or filterable API that passes request values
into them relies on this check.

## See also

- {doc}`persistence` — connections, drivers, `TransactionGuard`.
- {doc}`routing-validation` — `Hydrator`, `Absent`, and DTO rules.
- {doc}`appendix-packages` — the package's API catalogue.
