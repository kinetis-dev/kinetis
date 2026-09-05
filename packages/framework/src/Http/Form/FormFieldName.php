<?php

declare(strict_types=1);

namespace Kinetis\Http\Form;

/**
 * Turns a form field's name — `email`, `user[address][city]`, `tags[]`
 * — into a form `parse_str()` can register, so an adapter that parses a
 * body itself nests fields and files exactly the way a SAPI's own
 * parser does. Nesting rules, duplicate-name rules and PHP's own
 * top-level name mangling all come from `parse_str()` itself rather than
 * being restated here, where they could drift from it.
 *
 * Names arrive from a `Content-Disposition` header and are entirely
 * client-controlled, so each bracket segment is percent-encoded before
 * it goes back in: a name containing `&`, `=` or a raw newline has to
 * stay one name, not become two. The brackets themselves stay literal —
 * they are the structure.
 *
 * A name whose brackets don't close (`a[b`, `a]b[`) has no nesting to
 * express; it becomes one flat, fully-encoded key, which is the reading
 * that cannot silently invent structure the client never wrote.
 *
 * {@see depth()} reads the same structure the encoding writes, so the
 * depth a name is refused for and the depth it would have built are one
 * number rather than two that can drift.
 */
final class FormFieldName
{
    /**
     * @return string safe to concatenate into a `name=value` pair
     */
    public static function encode(string $name): string
    {
        $indices = self::indices($name);

        if ($indices === null) {
            return rawurlencode($name);
        }

        $encoded = rawurlencode(substr($name, 0, (int) strpos($name, '[')));

        foreach ($indices as $index) {
            $encoded .= '[' . rawurlencode($index) . ']';
        }

        return $encoded;
    }

    /**
     * The array levels this name builds: `email` is 1, `tags[]` is 2,
     * `user[address][city]` is 3.
     *
     * Read from the raw name rather than from the parsed result, because
     * `parse_str()` answers a name nested past its own
     * `max_input_nesting_level` by dropping the variable whole, without a
     * word — so a depth taken afterwards is a depth taken from a form
     * that may already be missing the field it was measuring. See
     * {@see FormLimits::assertNamesParseable()}, which is where this is
     * used and where that limit is met.
     */
    public static function depth(string $name): int
    {
        $indices = self::indices($name);

        return $indices === null ? 1 : count($indices) + 1;
    }

    /**
     * The bracket segments of an index expression — `['address',
     * 'city']` for `user[address][city]`, `['']` for `tags[]` — or null
     * when the name is not one: no brackets at all, or brackets that do
     * not account for every character after the first `[`.
     *
     * @return ?list<string>
     */
    private static function indices(string $name): ?array
    {
        $firstBracket = strpos($name, '[');

        if ($firstBracket === false) {
            return null;
        }

        $indices = substr($name, $firstBracket);

        if (preg_match_all('/\[([^\[\]]*)\]/', $indices, $matches, PREG_SET_ORDER) === 0) {
            return null;
        }

        // Every character after the first `[` has to belong to a
        // bracket pair; anything left over means the name is not the
        // index expression it looked like.
        if (implode('', array_column($matches, 0)) !== $indices) {
            return null;
        }

        return array_column($matches, 1);
    }
}
