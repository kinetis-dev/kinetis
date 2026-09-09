<?php

declare(strict_types=1);

namespace Kinetis\Validation;

/**
 * Which wire vocabulary a raw value was written in, and therefore which
 * spellings of a declared scalar type are admissible for it.
 *
 * A declared `int` means the same thing everywhere; what differs is what
 * the source is able to say. A JSON document distinguishes `42` from
 * `"42"` and promises the first; a query string, a path segment and a
 * form body carry text and nothing else, so `"42"` is the only spelling
 * they have. Reading both under one permissive rule made the JSON Schema
 * a route and an MCP tool publish untrue: the document says
 * `{"type": "integer"}` while the runtime also took a string.
 *
 * The source is chosen by whoever read the bytes — `Kinetis\Http\Dispatcher`
 * per request from the body's own media type, `Kinetis\Mcp\McpDispatcher`
 * for tool arguments — and travels with the value into
 * {@see Hydrator::resolveScalar()}, the one place the policy is applied.
 */
enum InputSource
{
    /**
     * A JSON document, already decoded: every value carries its own
     * type, so the declared type is taken literally. `string` accepts a
     * string; `int` accepts a JSON integer or a finite, in-range float
     * with no fractional part (`42.0` — JSON has one number type, and a
     * producer writing an integer that way still wrote an integer);
     * `float` accepts either JSON number; `bool` accepts `true`/`false`;
     * `array` and `iterable` accept a JSON array. A numeric or boolean
     * *string* is a string, and a field declaring anything else rejects
     * it.
     */
    case Json;

    /**
     * Text: a query string, a path segment, an
     * `application/x-www-form-urlencoded` or `multipart/form-data` body.
     * Every value is a raw string (or a list of them, for a repeated
     * key), so each scalar type has a canonical textual spelling and
     * that spelling is what binds: a plain base-10 integer for `int`, a
     * finite numeric literal for `float`, and `true`/`false`/`1`/`0` for
     * `bool`. A value spelled any other way is rejected rather than
     * coerced, and no scalar type accepts an array.
     */
    case Text;

    /**
     * PHP values a caller already holds — a database row handed to
     * {@see Hydrator::hydrate()} by `Kinetis\QueryBuilder\Query`, or an
     * array a service assembled itself. A driver decides on its own
     * whether a column arrives as an `int` or as its decimal string, so
     * this source admits both the JSON and the textual spelling of a
     * number, and `bool`'s `1`/`0`/`"1"`/`"0"` alongside real booleans.
     *
     * This is a source contract, not a compatibility shim: no request
     * ever selects it, and it is the default only for the direct
     * `hydrate()` entry point that no transport uses.
     */
    case Native;
}
