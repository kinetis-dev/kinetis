# Query Builder

````{note}
Not part of core. Install it separately:

```{code-block} sh
composer require kinetis/query-builder
```
````

A thin, parameterized SQL query builder over {doc}`persistence`'s
MySQL/Postgres drivers — not an ORM. No relationships, no migrations, no change-tracking, no
`save()`-on-a-model. It builds parameterized SQL and maps result rows into
typed DTOs via {doc}`routing-validation`'s `Hydrator` — the same mechanism
that hydrates a `#[Body]` request DTO.

A query never blocks the worker while waiting on the database, so several
independent ones can run side by side through {doc}`concurrency`'s
`concurrently()` instead of one after another.

```{code-block} php
use Kinetis\QueryBuilder\Query;

$orders = new Query($db)
    ->table('orders')
    ->where('customer_id', '=', $customerId)
    ->where('status', '!=', 'cancelled')
    ->orderBy('created_at', 'desc')
    ->limit(20)
    ->get(OrderRow::class);
```

## MySQL and Postgres

`Query` works with either backend through the same shared `Kinetis\Persistence\Contract\SqlLink`
family both drivers implement, auto-detected from the concrete connection
you pass in:

```{code-block} php
new Query($mysqlDb);    // MySqlDialect
new Query($postgresDb); // PostgresDialect
new Query($db, new PostgresDialect()); // explicit override
```

## A different database: named connections

`Query` takes whatever connection you hand it — including one built for a
named connection via `Kinetis\Persistence\SqlConnectionFactory` (see
{doc}`persistence`, {doc}`config`):

```{code-block} php
use Kinetis\Persistence\SqlConnectionFactory;

$reporting = SqlConnectionFactory::fromConfig($config, 'db2');
$orders = new Query($reporting)->table('orders')->get(OrderRow::class);
```

Identifier quoting (backtick vs double-quote) and retrieving a generated
primary key after an `INSERT` (MySQL exposes it on the result; Postgres
needs `RETURNING`) are isolated in a small `Dialect` interface. Everything
else — parameterized `?` placeholders, `LIMIT n OFFSET m`, affected-row
counts — is identical between the two.

A qualified column name is quoted per segment: `orders.total` becomes
`` `orders`.`total` `` (or `"orders"."total"` on Postgres), not one literal
identifier containing a dot. The one exception is a qualified wildcard —
`select('orders.*')` produces `` `orders`.* `` with the `*` segment left
unquoted, since quoting it (`` `orders`.`*` ``) asks the server for a real
column literally named `*` and it rejects that outright, rather than
expanding to every column the way an unqualified `*` does.

## Works inside `TransactionGuard`

`Query` accepts a plain connection pool or an in-flight
`Kinetis\Persistence\Contract\SqlTransaction` — both satisfy the same interface:

```{code-block} php
$transactions->transaction($db, function ($db) use ($data) {
    new Query($db)->table('orders')->insert([...]);
    new Query($db)->table('inventory')
        ->where('sku', '=', $data->sku)
        ->update(['stock' => $newStock]);
});
```

See {doc}`persistence` for `TransactionGuard`'s commit/rollback behavior.

## Reading: `get()`, `first()`, `count()`

```{code-block} php
$rows = new Query($db)->table('users')->where('active', '=', true)->get();       // list<array<string, mixed>>
$rows = new Query($db)->table('users')->where('active', '=', true)->get(UserRow::class); // list<UserRow>
$user = new Query($db)->table('users')->where('id', '=', $id)->first(UserRow::class);    // UserRow|null
$total = new Query($db)->table('orders')->where('status', '=', 'paid')->count();          // int
```

Pass a DTO class and each row is hydrated through `Hydrator::hydrate()`,
constraints included (`#[Email]`, `#[MinLength]`, ...); omit it and you get
plain arrays.

## Pagination: `paginate()`, `cursorPaginate()`

Two ways to page through a result set, returning a plain value object a
controller can hand straight back — it encodes to JSON exactly like any
other `readonly` DTO, with no extra step:

```{code-block} php
#[Get('/orders')]
public function index(#[Query] int $page = 1, #[Query] int $perPage = 20): Paginator
{
    return new Query($this->db)->table('orders')->orderBy('id')->paginate($perPage, $page);
}
```

```{code-block} json
:caption: GET /orders?page=2&perPage=20

{
    "data": [{"id": 21, "...": "..."}, {"...": "..."}],
    "currentPage": 2,
    "perPage": 20,
    "total": 145,
    "lastPage": 8
}
```

`paginate(int $perPage, int $page = 1, ?string $dtoClass = null)` runs a
`count()` for `total` and a `limit()`/`offset()`-based `get()` for the
page itself — both against the same `where()`/`join()` filters already on
the query. A page past the last one returns an empty `data` array with
the real `total`/`lastPage` still reported, not an error.

Cursor-based pagination advances by the last row's own column value
instead of a page number, so rows inserted or deleted between requests
can't shift results the way an offset-based page number can — a better
fit for a large or fast-changing table:

```{code-block} php
#[Get('/orders')]
public function index(#[Query] ?string $cursor = null): CursorPaginator
{
    return new Query($this->db)->table('orders')->cursorPaginate(perPage: 20, cursor: $cursor);
}
```

```{code-block} json
:caption: GET /orders, then GET /orders?cursor=145

{"data": ["...", "..."], "nextCursor": "165", "hasMore": true}
```

`cursorPaginate(int $perPage, ?string $cursor, string $cursorColumn = 'id', ?string $dtoClass = null, ?string $cursorAlias = null)`
orders the query by `$cursorColumn` itself and filters
`WHERE $cursorColumn > $cursor` once a cursor is given — `null` (the first
call) fetches from the start. The cursor is the column's own raw value,
not an encoded token; nothing here is sensitive, so there's no reason to
obscure it. There's no total count and no page number — that's the actual
tradeoff for avoiding `COUNT(*)` on a table where that query would be
expensive, and it means a client can't jump to an arbitrary page, only
"give me the next one."

```{warning}
`$cursorColumn` must be unique and strictly monotonic — a primary key or
an auto-incrementing/serial column, not e.g. `created_at`, which two rows
can share. A page boundary landing inside a run of equal values silently
skips whatever's left of that run: `WHERE $cursorColumn > ?` only
excludes rows up to and including the value already seen, not "rows
already seen."

`cursorPaginate()` also always orders by `$cursorColumn` itself. Adding
your own `orderBy()` call on a *different* column can make it skip or
repeat rows for the same reason — the `WHERE $cursorColumn > ?`
comparison only makes sense against the column the results are actually
ordered by.
```

`nextCursor` always comes out of the same result as the rows you were
handed — never a second query. Two reads of a live table are not one
snapshot, and a cursor pointing at a row you were never given would
silently skip everything between the two.

Computing it needs `$cursorColumn` in every row regardless of what your
own `select()` call asked to see, so a projection that omits it
(`->select('name')->cursorPaginate(...)`) still works correctly —
`$cursorColumn` is added to the query automatically and stripped back
out of every returned row (and never reaches `$dtoClass` hydration
either) before the method returns, so the projection you actually get
back is exactly the one you asked for. Selecting it yourself, or using
the default `*`, leaves it in the result as normal.

### Paginating a joined query: `cursorAlias`

On a `join()`ed query you generally want a *qualified* cursor column
(`orders.id`) to say which table's `id` you mean. That needs one more
argument, because MySQL and Postgres both report an unaliased qualified
column under its plain name — `id`, not `orders.id` — which the joined
table's own `id` collides with. A PHP row is an associative array, so
two columns arriving under one key silently become one.

Kinetis won't guess a name that's safe against your projection, because
none is: pick one yourself with `cursorAlias`.

```{code-block} php
:caption: The column is selected under your alias, read from it, then removed
return new Query($this->db)->table('orders')
    ->join('customers', 'orders.customer_id', '=', 'customers.id')
    ->select('orders.total', 'customers.name')
    ->cursorPaginate(perPage: 20, cursor: $cursor, cursorColumn: 'orders.id', cursorAlias: 'order_cursor');
```

The alias is appended to your projection, read back, and stripped from
every returned row before you see them — so the rows still contain
exactly `total` and `name`. Nothing else about the query changes: an
alias an `orderBy()` depends on stays where you put it, and an
`offset()` you set stays yours.

Pass a qualified `$cursorColumn` without an alias and you get an
`InvalidArgumentException` naming the parameter, not a silently wrong
cursor. `cursorAlias` works for an unqualified column too, which is how
you disambiguate a projection that already has a *different* column of
that name.

```{warning}
Choosing an alias nothing else in the projection uses is yours to get
right, exactly as it is for any `AS` you write by hand. Pick a name a
column already answers to and the cursor **replaces** that column: it
takes the key in the returned row, and the cleanup that removes the
alias removes your field with it. The cursor itself stays correct; the
row just comes back one field short.

Kinetis rejects the half of this it can see. An alias matching a column
you listed yourself — `select('row_cursor')`, or `select('t.row_cursor')`,
which resolves to the same key — throws `InvalidArgumentException`
before any SQL runs. A column that only a wildcard brings in can't be
checked the same way: knowing what `*` expands to needs column metadata
the result doesn't carry, and the one available check — counting
distinct keys against the server's column count — also fires on the
duplicate `id` every `SELECT *` across a join produces, which is the
most common reason to want a cursor alias in the first place. So with a
wildcard, the name is yours to keep clear.
```

`perPage`/`page` (for `paginate()`) and `perPage` (for `cursorPaginate()`)
must be at least 1 — either method throws `InvalidArgumentException`
otherwise, rather than compiling a nonsensical `LIMIT 0`/negative
`OFFSET`. Neither method caps how *large* `$perPage` can be — a request
for `?perPage=1000000` is passed straight through. Capping it, if your
application needs one, is a normal application-level concern (clamp it in
the controller before calling either method), the same way `Query`
doesn't validate a `where()` value either.

### Describing the item shape in OpenAPI

`Paginator`/`CursorPaginator` are the same two classes for every paginated
route, regardless of what each one actually holds, so the generated
OpenAPI document describes `data` as a bare object by default —
reflecting the return type alone can't recover what's inside it.
`#[PaginatedItem]` names it explicitly:

```{code-block} php
use Kinetis\Http\Attributes\PaginatedItem;

#[Get('/orders')]
#[PaginatedItem(OrderResponse::class)]
public function index(#[Query] int $page = 1, #[Query] int $perPage = 20): Paginator
{
    return new Query($this->db)->table('orders')->orderBy('id')->paginate($perPage, $page);
}
```

`data` now describes as an array of `OrderResponse`'s own schema,
deduplicated into `components/schemas` the same way a nested DTO already
is. Purely descriptive — nothing checks that the route actually returns
that item type at runtime, the same trust already placed in
`#[Response(status, description)]`'s own status code.

## Writing: `insert()`, `insertGetId()`, `update()`, `delete()`

```{code-block} php
new Query($db)->table('users')->insert(['email' => $email, 'name' => $name]);

$id = new Query($db)->table('users')->insertGetId(['email' => $email], primaryKey: 'id');

$affected = new Query($db)->table('users')->where('id', '=', $id)->update(['name' => $newName]);

$deleted = new Query($db)->table('users')->where('id', '=', $id)->delete();
```

`update()`/`delete()` return the affected-row count. `insert()`/
`insertGetId()`/`update()` all reject an empty `$data` array with
`InvalidArgumentException` — an empty array compiles to invalid SQL
(`INSERT INTO t () VALUES ()`, `UPDATE t SET  WHERE ...`) rather than
anything meaningful, and this class has no `DEFAULT VALUES` shorthand for
the (rare) case that's genuinely intended.

## Raw SQL

A plain `SqlLink`/`SqlTransaction` and `$db->execute(...)` — see
{doc}`persistence` — bypasses the builder entirely with no special support
needed.

For raw fragments inside an otherwise-fluent query:

```{code-block} php
new Query($db)->table('orders')
    ->selectRaw('COUNT(*) as total, DATE(created_at) as day')
    ->whereRaw('YEAR(created_at) = ?', [2026])
    ->orderByRaw('RAND()')
    ->get();
```

```{danger}
`whereRaw()`'s `$params` are bound as real parameters, in the exact
position their `?` appears in `$sql` — never string-interpolated. Building
`$sql` by concatenating a user-controlled value instead of passing it
through `$params` reintroduces exactly the injection risk parameterized
queries exist to prevent.
```

## Parameter order

Structured `where()` calls, `whereIn()`, and `whereRaw()` fragments can all
be mixed in one query; their bound values always appear in the same order
as the `?` placeholders in the generated SQL:

```{code-block} php
new Query($db)->table('orders')
    ->where('customer_id', '=', 7)
    ->whereRaw('YEAR(created_at) = ?', [2026])
    ->whereIn('status', ['pending', 'paid'])
    ->where('total', '>', 100)
    ->get();
// WHERE `customer_id` = ? AND YEAR(created_at) = ? AND `status` IN (?, ?) AND `total` > ?
// params: [7, 2026, 'pending', 'paid', 100]
```

```{warning}
One `Query` instance is one query. `table()`/`select()`/`where()`/...
mutate and accumulate on the same instance — nothing resets between calls.
Construct a fresh `new Query($link)` per query; reusing one instance
across separate queries merges their `where()`s together.
```

## How a value reaches the database: literal or bound parameter

A `Query` binds its values as real parameters, or writes them into the
SQL text as literals, and which one it picks depends on the driver
underneath it. Both produce the same rows; the difference is only how the
value physically reaches the database.

**On the native MySQL and Postgres drivers, `int` and `bool` values are
written as literals.** Those drivers reach the server once for a query
carrying no parameters and twice for a prepared one, so a query whose
values are all safely representable saves a round trip. Nothing else is
ever inlined: `string`, `null` and `float` always bind. A string literal
would depend on connection charset and SQL-mode state the builder
deliberately knows nothing about, and `(string)` on a float can produce
`NAN` or `INF`, neither of which is valid SQL.

**On the PDO drivers, every value binds.** They run with native prepares
and memoize the prepared statement per connection, so binding costs one
round trip after the first and keeps the binary protocol — while an
unparameterized query drops to the text protocol and measures about half
again as expensive per query. The drivers say which they prefer by
carrying `Kinetis\Persistence\Contract\PrefersPreparedStatements`; a
third-party link can declare the same.

Two rules apply either way. A query is fully inlined or fully
parameterized, never a mix — one value that must bind makes the whole
query bind. And `whereRaw()`, `selectRaw()` or `orderByRaw()` anywhere in
a query disables inlining for all of it, since raw SQL text may contain a
`?` that was never meant as a placeholder.

## Operators, directions, join types, and boolean conjunctions are allow-listed

`where()`'s `$operator`, `orderBy()`'s `$direction`, `join()`'s
`$type`/`$operator`, and `where()`/`whereIn()`/`whereRaw()`'s `$boolean`
are all checked against a fixed set — not bound as `?` like a value,
since none of them can be (SQL doesn't allow a parameter in an
operator/keyword position), but not passed through unchecked either. An
unrecognized value throws `InvalidArgumentException` immediately, rather
than reaching the generated SQL:

```{code-block} php
->where('id', '=', 5)      // ok
->where('id', '>=', 5)     // ok
->where('id', $userInput)  // throws if $userInput isn't one of =, !=, <>, <, <=, >, >=, LIKE, NOT LIKE

->orderBy('name', 'asc')   // ok — case-insensitive
->orderBy('name', $sort)   // throws unless $sort is ASC or DESC

->join('customers', 'orders.customer_id', '=', 'customers.id', 'left') // ok
->join('customers', 'orders.customer_id', '=', 'customers.id', $type)  // throws unless $type is INNER, LEFT, RIGHT, FULL, or CROSS

->where('active', '=', 1, 'or')            // ok — case-insensitive
->where('active', '=', 1, $userBoolean)    // throws unless $userBoolean is AND or OR
```

This matters specifically because a sortable/filterable API
(`?sort=name&dir=asc&op=gte`) is exactly the shape that passes a client
value into one of these slots — every other value or identifier in this
class is already safe by construction (bound as `?`, or identifier-quoted
via `Dialect::quoteIdentifier()`), but an operator/direction/join-type/
boolean is neither a value nor a plain identifier, so each needed its own
check rather than inheriting safety from one of those two existing
mechanisms. A generic filter builder that maps a request value straight
into `$boolean` is exactly as real a risk as the operator/direction case
above — the same check applies to it.

`whereIn()` with an empty array compiles to a constant-false predicate
(`1 = 0`) instead of the syntactically invalid `IN ()` both MySQL and
Postgres reject outright — filtering by an empty result set (a user's
post list, when that user turned out to have no matching orders) is a
real, common case, not an edge case worth leaving broken.

## See also

- {doc}`persistence` — connecting to MySQL/Postgres, `TransactionGuard`,
  and caching query results.
- {doc}`routing-validation` — more on `Hydrator`, including nested-DTO
  support.
