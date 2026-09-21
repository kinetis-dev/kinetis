# Appendix: Query Builder

The complete `kinetis/query-builder` contract behind {doc}`query-builder`:
row mapping, the clauses and writes the builder compiles, what each
dialect receives, and every combination refused before a statement runs.
{doc}`appendix-packages` lists the package's classes.

## Queries and links

`new Query($link)` takes a `MysqlLink`, a `PostgresLink` or a
`SqlTransaction`, and the link's `MysqlLink` or `PostgresLink` marker
picks the dialect. A variable typed `SqlTransaction` is accepted because
every built-in transaction also carries its link's dialect marker; a
`SqlTransaction` carrying neither marker throws `QueryBuilderException`
instead of guessing a dialect.

The read terminals that add a limit, order or projection — `first()`,
`value()`, `paginate()` and `cursorPaginate()` — apply it to a copy, so
the builder you hold is unchanged afterwards. `toSelectSql()`,
`toUpdateSql($values)` and `toDeleteSql()` return the SQL and bindings
without running them.

(query-builder-reference-mapping)=
## Row mapping

`get()`, `first()`, `paginate()` and `cursorPaginate()` take an optional
DTO class. `Kinetis\QueryBuilder\RowMapper` reflects that class once per
result set and passes each constructor parameter the column of exactly
its name, case-sensitively:

- Columns no parameter names are ignored.
- A missing column leaves its parameter to the declared default. A
  missing column for a parameter without a default is refused, even when
  the parameter is nullable.
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
is data from your own database, not client input.

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

## Selecting and filtering

- `select()` takes column names only; name a computed or renamed column
  with `selectRaw()`. Once `selectRaw()`, `selectSub()` or
  `selectExists()` is used without `select()`, the default `*` is
  dropped.
- `value()` and `pluck()` read the column from each row by its result
  name and leave the projection as you built it. A qualified column
  (`articles.slug`) arrives under its last segment. A name missing from
  the row throws `QueryBuilderException`.
- Groups nest, and a group that adds nothing compiles to nothing.
- `whereRaw()` refuses an empty fragment.
- Only comparisons treat `null` specially; inserted and updated null
  values bind normally.

(query-builder-reference-joins)=
## Joins

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

(query-builder-reference-subqueries)=
## Subqueries

Pass a `Query` wherever a subquery is accepted. It is compiled at the
moment you pass it, so changing it afterwards does not change the outer
query. It must be built on the same kind of connection, and it cannot
carry `with()` or a row lock; `with()` belongs on the outermost query,
where subqueries can select from it by name.

### `EXISTS`

```{code-block} php
$favorited = new Query($db)
    ->table('favorites')
    ->whereColumn('favorites.article_id', '=', 'articles.id')
    ->where('favorites.user_id', '=', $userId);

$rows = new Query($db)->table('articles')->whereExists($favorited)->get();
```

`whereExists()`, `orWhereExists()`, `whereNotExists()` and
`orWhereNotExists()` take a subquery that may refer to the outer query's
tables. The same predicates work in `update()` and `delete()`:

```{code-block} php
new Query($db)
    ->table('articles')
    ->whereNotExists(new Query($db)->table('comments')->whereColumn('comments.article_id', '=', 'articles.id'))
    ->where('published_at', '<', $cutoff)
    ->delete();
```

MySQL 8.4 rejects an `UPDATE` or `DELETE` whose subquery reads the table
being changed (error 1093); MariaDB and PostgreSQL accept it.

### `IN`

```{code-block} php
$followed = new Query($db)
    ->table('follows')
    ->select('followee_id')
    ->where('follower_id', '=', $userId);

$feed = new Query($db)->table('articles')->whereIn('author_id', $followed)->get();
```

`NOT IN` against a subquery that returns a `NULL` matches no row, as SQL
defines it. On MySQL and MariaDB, an `IN` subquery carrying `limit()` or
`offset()` throws `QueryBuilderException`: both servers reject that
statement. Select from the limited query with `fromSub()` instead, which
all three servers accept:

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

`selectExists()` selects whether a subquery returns a row, as `1` or `0`
on every server, so a `bool` DTO parameter maps from it:

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

(query-builder-reference-aggregates)=
## Grouping and aggregates

- `groupBy(string ...$columns)` and `groupByRaw($sql, $params)`.
- `having()`/`orHaving()` compare a grouped column with the same
  operators and null handling as `where()`.
- `havingRaw($sql, $params)` compares an aggregate.

On a query with `distinct()`, `groupBy()`, a `having` clause or a set
operation, `count()`, `sum()`, `min()`, `max()` and `avg()` aggregate the
rows the query returns. They compile as
`SELECT COUNT(*) FROM (the query) AS aggregate_source` (or `SUM(column)`,
and so on), keeping every binding the rows depend on. The column an
aggregate names is resolved against that derived table, so pass the
unqualified output name the query's select list gives it: over a query
selecting `COUNT(*) AS articles` per author, `sum('articles')` totals the
per-author counts. MySQL and MariaDB reject the derived table when two
selected columns share an output name (`a.id` and `b.id`): select them
under distinct names.

On any other query the aggregates run over the filtered and joined rows
directly, and the select list plays no part.

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

(query-builder-reference-pagination)=
## Pagination

`cursorPaginate(int $perPage, ?string $cursor, string $cursorColumn = 'id', ?string $dtoClass = null, ?string $cursorAlias = null)`
orders by `$cursorColumn`, filters `$cursorColumn > $cursor` once a cursor
is given, and reads `nextCursor` from the last delivered row in the same
query. The cursor filter wraps your own predicates as
`(existing predicate) AND id > ?`, so an `orWhere()` cannot escape it. A
projection that omits the cursor column still works: the column is
selected, read, and removed from every row before mapping.

On a joined query, pass a qualified cursor column and name an alias for
it. Both server families return `orders.id` under the bare key `id`,
which a joined table's `id` would overwrite:

```{code-block} php
return new Query($this->db)
    ->table('orders')
    ->join('customers', 'orders.customer_id', '=', 'customers.id')
    ->select('orders.total', 'customers.name')
    ->cursorPaginate(perPage: 20, cursor: $cursor, cursorColumn: 'orders.id', cursorAlias: 'order_cursor');
```

A qualified column without an alias, or an alias equal to a column you
listed in `select()`, throws `InvalidPaginationException`. An alias that
a wildcard's columns already use cannot be detected: that column is
replaced in the returned rows, so pick a name nothing in the projection
uses.

`InvalidPaginationException` is an `InvalidArgumentException` naming the
argument it refuses.

## Writes

- `insert()` runs one statement for a row or a batch. A batch binding
  more than 65,535 values throws `InvalidArgumentException`; split it
  yourself, knowing each call is its own statement.
- `insertGetId(array $values, string $primaryKey = 'id')` inserts one row
  and returns its generated key.
- `upsert(array $values, array $uniqueBy, array $update)` takes a row or
  a batch, and returns the server's affected-row count.
- `increment()` and `decrement()` take an `int|float` amount (1 by
  default) and an optional map of further `column => value` assignments:
  `decrement('stock', 2, ['sold_at' => $now])`.

`insertUsing()` inserts the rows a select returns, and the select may
carry its own `with()`:

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

### What each write refuses

| Write | Refuses |
| --- | --- |
| `update()`, `increment()`, `decrement()`, `delete()` | no where predicate; `with()`, a table alias, `fromSub()`, `distinct()`, `select()`/`selectRaw()`/`selectSub()`/`selectExists()`, any join, grouping, `having`, set operations, ordering, `limit()`, `offset()`, a lock |
| `insert()`, `insertGetId()`, `insertUsing()`, `insertOrIgnore()`, `upsert()` | all of the above, and any where predicate |

`increment()` also refuses assigning its own column through its extra
assignments. `upsert()` refuses an empty `$uniqueBy` or `$update` and a
column in either that is not inserted.

(query-builder-reference-upsert)=
### Upsert on MySQL and MariaDB

- The shared spelling is `VALUES(col)`. MariaDB has no `INSERT ... AS`
  row alias, and MySQL 8.4 runs `VALUES(col)` with deprecation warning
  1287.
- A conflict on any unique key of the table triggers the update;
  `$uniqueBy` is validated but does not reach the SQL. PostgreSQL updates
  only on a conflict with exactly `$uniqueBy`'s constraint.
- The affected-row count is 1 per inserted row, 2 per updated row and 0
  per row left unchanged. PostgreSQL counts 1 per row.

(query-builder-reference-locks)=
## Locks

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
transaction (see {doc}`appendix-database`).

(query-builder-reference-dialects)=
## Dialect spellings

| Feature | MySQL 8.4 and MariaDB 11.4 | PostgreSQL 16 |
| --- | --- | --- |
| Identifier quoting | `` `name` `` | `"name"` |
| `offset()` without `limit()` | `LIMIT 18446744073709551615 OFFSET n` | `OFFSET n` |
| `lockForShare()` | `LOCK IN SHARE MODE` | `FOR SHARE` |
| `insertOrIgnore()` | `ON DUPLICATE KEY UPDATE first_column = first_column` | `ON CONFLICT DO NOTHING` |
| `upsert()` | `ON DUPLICATE KEY UPDATE col = VALUES(col)` | `ON CONFLICT (unique columns) DO UPDATE SET col = EXCLUDED.col` |
| `insertGetId()` | the driver's last insert id | `RETURNING` the key |
| `IN` subquery with `limit()`/`offset()` | refused | compiled |

Everything else compiles identically. The two `insertOrIgnore()` spellings
do not skip the same conflicts.

(query-builder-reference-insert-or-ignore)=
### Which conflicts `insertOrIgnore()` skips

- MySQL and MariaDB resolve a conflict on any unique key of the table and
  nothing else. The spelling assigns a column to itself instead of using
  `INSERT IGNORE`, which also turns other errors into warnings and writes
  the row.
- PostgreSQL names no conflict target, so `ON CONFLICT DO NOTHING` skips
  a conflict on any unique constraint and on an exclusion constraint as
  well — an overlapping `tstzrange` under `EXCLUDE USING gist`, which
  `insert()` raises as SQLSTATE `23P01`.
- The returned count is rows inserted; it does not say which constraint
  held a row back. Use `insert()` when the conflict must be observable:
  it raises a `QueryException` whose `getSqlState()` can be inspected.

On both families every other error still fails the whole statement and
writes nothing.

(query-builder-reference-values)=
## How values reach the database

On the native MySQL and PostgreSQL drivers, a statement whose every value
is an `int` or `bool` is sent with those values written as literals; any
`string`, `float` or `null` makes the whole statement bind. On the PDO
drivers, which carry
`Kinetis\Persistence\Contract\PrefersPreparedStatements`, every value
binds. A raw fragment whose text contains a `?` — including one inside a
subquery, CTE, operand or join — makes the whole statement bind, since
that `?` may not be a placeholder. Raw text without a `?` leaves the
literals in place.

## Allow-listed keywords

Operators (`=`, `!=`, `<>`, `<`, `<=`, `>`, `>=`, `LIKE`, `NOT LIKE`),
order directions (`ASC`, `DESC`), join types (`INNER`, `LEFT`, `RIGHT`)
and the `$boolean` argument (`AND`, `OR`) are checked when set and throw
`InvalidArgumentException` for anything else. They cannot be bound as
parameters, so a sortable or filterable API that passes request values
into them relies on this check.

## See also

- {doc}`query-builder` — everyday queries and the repository cookbook.
- {doc}`appendix-database` — the drivers and transactions underneath.
