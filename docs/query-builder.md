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
in, and maps result rows into typed DTOs with its own `RowMapper`.
Anything outside that shared surface stays available as raw SQL on the
same connection. In production it depends only on `kinetis/persistence`,
so it runs inside a Kinetis application or without one.

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
Pass user input only through value slots — a `where()` value, an insert
or update map, a raw fragment's `$params`. The builder sends those as
bound parameters or as literals it writes itself (see
[How values reach the database](#how-values-reach-the-database)).
Identifiers (table and column names) are quoted but not validated, and
raw SQL fragments are inserted as written — never build either from
user input.
```

## Creating a query

`new Query($link)` takes a connection or an open transaction. The link's
type picks the SQL spelling: a `MysqlLink` compiles for MySQL and
MariaDB, a `PostgresLink` for PostgreSQL. A variable typed
`SqlTransaction` is accepted because every built-in transaction also
carries its link's dialect marker; a `SqlTransaction` carrying neither
marker throws `QueryBuilderException` instead of guessing a dialect.

### Standalone

Build the connection with `kinetis/persistence` and pass it in:

```{code-block} php
use Kinetis\Persistence\ConnectionDefinition;
use Kinetis\Persistence\SqlConnectionFactory;
use Kinetis\QueryBuilder\Query;

$db = SqlConnectionFactory::create(new ConnectionDefinition(
    dialect: 'mysql',
    host: 'db.internal',
    database: 'shop',
    user: 'shop',
    password: $password,
));

$open = new Query($db)->table('orders')->where('status', '=', 'open')->count();
```

The application builds each client once, closes it at shutdown, and
uses a `TransactionGuard` per unit of work — see {doc}`persistence`.

### In a Kinetis application

`kinetis/database-bridge` binds the default connection from `DB_*`
configuration, so a controller or repository injects `MysqlLink` or
`PostgresLink` with no wiring of its own:

```{code-block} php
new Query($db);  // the injected connection, see persistence
new Query($tx);  // an open transaction, see "Transactions and row locks"

use Kinetis\DatabaseBridge\ConnectionFactory;

$reporting = ConnectionFactory::fromConfig($config, 'reporting');
$totals = new Query($reporting)->table('daily_totals')->get();
```

### Building and running

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

Pass a DTO class and each row is mapped onto it — see
[Mapping rows to DTOs](#mapping-rows-to-dtos).

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

## Mapping rows to DTOs

`get()`, `first()`, `paginate()` and `cursorPaginate()` take an optional
DTO class. `Kinetis\QueryBuilder\RowMapper` reflects that class once per
result set and passes each constructor parameter the column of exactly
its name, case-sensitively:

- Columns no parameter names are ignored.
- A missing column leaves its parameter to the declared default. A
  missing column for a parameter without a default is refused, even
  when the parameter is nullable.
- A class without a constructor is constructed with no arguments.
- Every value is checked before the constructor runs. An exception the
  constructor throws is the DTO's own invariant and propagates
  unchanged.

A parameter's declared type decides which row values it admits:

| Declared type | Admits | Passes |
| --- | --- | --- |
| none, or `mixed` | any value, `null` included | the value unchanged |
| `string` | a `string` | the string |
| `int` | an `int`, or its canonical decimal string: `"42"` and `"-7"`, but not `"042"`, `"+7"`, `" 7"`, `"7.0"`, `"7e0"` or a value beyond PHP's `int` range | an `int` |
| `float` | a finite `int` or `float`, or a numeric string with a finite value | a `float` |
| `bool` | a `bool`, `0`, `1`, `"0"` or `"1"` | a `bool` |
| a backed enum | a case of that enum, or a value the `string` or `int` row above admits for its backing type that names a case | the case |

`?T` and `T|null` also admit `null`; no other type does. Nothing else is
converted — no arrays, nested DTOs, dates, other objects or JSON text —
and validation attributes such as `#[NotBlank]` are not evaluated: a row
is data from your own database, not client input. Select a scalar and
convert it in the constructor, or read the row as an array.

`RowMapper::for()` refuses, before any row is read, a class that cannot
be instantiated and a constructor parameter that is variadic, passed by
reference, or declared with an intersection type, a union other than
`T|null`, or any other builtin or class type. The mapper is public for
rows from elsewhere:

```{code-block} php
use Kinetis\QueryBuilder\RowMapper;

$article = RowMapper::for(ArticleRow::class)->map(['id' => '7', 'title' => 'Hello', 'slug' => 'hello']);
// ArticleRow with id 7
```

Every failure is a `Kinetis\QueryBuilder\Exception\RowMappingException`,
an `InvalidArgumentException`:

| Factory | Thrown when |
| --- | --- |
| `unsupportedDefinition()` | `for()` meets a class or parameter outside the admitted types |
| `missingColumn()` | a row has no column for a parameter without a default |
| `invalidValue()` | a value is not admitted by its parameter's type, including `null` for a non-nullable one |
| `unknownEnumCase()` | an admitted backing value names no case |

A message names the DTO class, the parameter and the expected shape, and
describes the value only by its type, never by its contents.

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
`$params`. Once `selectRaw()`, `selectSub()` or `selectExists()` is
used without `select()`, the default `*` is dropped.

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

`selectExists()` selects whether a subquery returns a row, as `1` or
`0` on every server, so a `bool` DTO parameter maps from it:

```{code-block} php
$following = new Query($db)
    ->table('follows')
    ->whereColumn('follows.followee_id', '=', 'articles.author_id')
    ->where('follows.follower_id', '=', $viewerId);

$cards = new Query($db)
    ->table('articles')
    ->select('articles.id', 'articles.title')
    ->selectExists($following, 'following')
    ->get(ArticleCardRow::class); // application-owned DTO with a `bool $following` parameter
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
selected, read, and removed from every row before mapping.

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
`InvalidPaginationException`, an `InvalidArgumentException` naming the
argument. Neither caps `perPage`. Bound request values before they reach
the query: in a Kinetis controller, a rule such as `#[GreaterThan(0)]`
on the `#[Query]` parameter refuses a bad value as a validation failure
(see {doc}`routing-validation`), and clamping keeps `perPage` in range.

`Kinetis\QueryBuilder\Paginator` and `CursorPaginator` are plain
readonly envelopes: returned from a Kinetis controller, their public
fields encode as the JSON shown above.

### Describing the page item in OpenAPI

In a Kinetis application, `#[PaginatedItem]` names the item class of any
response wrapper whose `data` is the item list — `Paginator`,
`CursorPaginator`, or a wrapper of your own. The generated schema then
describes the wrapper inline, with `data` as an array of that class's
schema. Without the attribute, the wrapper is an ordinary schema
component whose `data` is a bare array:

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
grouped query above counts authors, not articles, and `sum()`, `min()`,
`max()` and `avg()` take a column name its select list exposes —
`sum('articles')` there totals the per-author counts. On any other query
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

Pass the transaction to `Query` to run statements inside it. Type the
callback against the generic `SqlTransaction` for code like this, which
stays on the shared SQL/query-builder surface: `Query` detects the
dialect from the concrete transaction object it receives (a
`MysqlTransaction` for a `MysqlLink`), not from the callback's declared
parameter type (see {doc}`persistence`'s "Transactions"):

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
  name, a property both renamed and excluded, and two properties
  mapping to one column all throw.

It does no case conversion, date formatting or nesting. Hash a password
or format a timestamp before extracting. An object with nothing to write
yields `[]`, which the writes refuse.

(query-builder-partial-updates)=
### Partial updates

`fromObject()` writes every public property it reads, so a partial
update chooses its columns before extracting. A Kinetis request DTO
that marks omitted members with `Kinetis\Validation\Absent` (see
{doc}`routing-validation`) needs that step: `Absent` is a framework
request concept the query builder does not recognize, and an
`Absent::Value` property is a unit enum, which throws. Derive the
omitted properties and pass them as `$except`:

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

Writes return counts and ids, not rows. When the caller needs the
stored row, read it in the same transaction:

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
  `TransactionGuard` is request-scoped, so
  every request gets its own. On PostgreSQL, inject `PostgresLink`
  instead; `register()`'s callback stays typed `SqlTransaction` either
  way (see "Transactions and row locks" above).
- **Conflicts.** `register()` does not read for an existing username
  first: another request can insert the same one between that read and
  this write. The unique keys decide, `isUniqueViolation()` recognizes
  the answer on every driver, and the guard has already rolled the
  transaction back when the catch runs — see {doc}`persistence`'s
  "Unique violations".
- **Flags.** `selectExists()` selects `1` or `0`, which
  `UserCardRow::$following` receives as a `bool`.
- **Large tables.** A result is buffered whole, so `eachBatch()` reads
  bounded pages with `cursorPaginate()` rather than selecting the table
  at once. Each page is its own statement: a row inserted meanwhile
  appears on a later page, and no row is delivered twice.

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

### Aggregates over a derived table

On a `distinct()`, grouped, `having` or set-operation query, `count()`,
`sum()`, `min()`, `max()` and `avg()` compile as
`SELECT COUNT(*) FROM (the query) AS aggregate_source` (or `SUM(column)`,
and so on), keeping every binding the rows depend on. The column an
aggregate names is resolved against that derived table, so pass the
unqualified output name the query's select list gives it. MySQL and
MariaDB reject the derived table when two selected columns share an
output name (`a.id` and `b.id`): select them under distinct names.

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
| `update()`, `increment()`, `decrement()`, `delete()` | no where predicate; `with()`, a table alias, `fromSub()`, `distinct()`, `select()`/`selectRaw()`/`selectSub()`/`selectExists()`, any join, grouping, `having`, set operations, ordering, `limit()`, `offset()`, a lock |
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
binds. A raw fragment whose text contains a `?` — including one inside
a subquery, CTE, operand or join — makes the whole statement bind,
since that `?` may not be a placeholder. Raw text without a `?` leaves
the literals in place.

### Allow-listed keywords

Operators (`=`, `!=`, `<>`, `<`, `<=`, `>`, `>=`, `LIKE`, `NOT LIKE`),
order directions (`ASC`, `DESC`), join types (`INNER`, `LEFT`, `RIGHT`)
and the `$boolean` argument (`AND`, `OR`) are checked when set and throw
`InvalidArgumentException` for anything else. They cannot be bound as
parameters, so a sortable or filterable API that passes request values
into them relies on this check.

## See also

- {doc}`persistence` — connections, drivers, `TransactionGuard`.
- {doc}`routing-validation` — request DTOs, validation, and `Absent`.
- {doc}`appendix-packages` — the package's API catalogue.
