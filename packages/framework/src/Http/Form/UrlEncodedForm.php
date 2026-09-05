<?php

declare(strict_types=1);

namespace Kinetis\Http\Form;

/**
 * Parses an `application/x-www-form-urlencoded` body into
 * `getParsedBody()`, with {@see FormLimits} enforced around the parse
 * rather than left to it.
 *
 * Everything that bounds the parse is read off the raw body, before
 * parsing, because that is the only point at which the real numbers are
 * knowable. `a=1` repeated a thousand times is a thousand pairs on the
 * wire and one leaf afterwards; a name nested past this runtime's
 * `max_input_nesting_level` is one pair on the wire and nothing at all
 * afterwards. A count taken from the parsed result sees neither — which
 * is exactly the shape that lets a body sail past a limit checked on the
 * output while a field the application needed is quietly missing. A body
 * over any ceiling is refused with a `413`; nothing is ever handed back
 * partially parsed.
 *
 * Counting, naming and parsing all read the same separator because
 * {@see FormPairs} makes them: `&`, or the body is refused before any of
 * the three runs.
 */
final class UrlEncodedForm
{
    /**
     * @return array<array-key, mixed>
     */
    public static function parse(string $body, FormLimits $limits): array
    {
        $limits->assertRawPairCount(self::countPairs($body));
        $limits->assertNamesParseable(self::names($body));

        $fields = FormPairs::parse($body);

        $limits->assertFormWithinLimits($fields, []);

        return $fields;
    }

    /**
     * The names the parse is about to be handed, decoded the way
     * `parse_str()` decodes them: percent-escapes and `+` resolved first,
     * so `a%5Bb%5D` is read as the two levels it builds rather than the
     * one flat key it looks like. An empty run between separators names
     * nothing and registers nothing, so it is not one.
     *
     * @return list<string>
     */
    private static function names(string $body): array
    {
        $names = [];

        foreach (explode(FormPairs::SEPARATOR, $body) as $pair) {
            if ($pair === '') {
                continue;
            }

            $names[] = urldecode(explode('=', $pair, 2)[0]);
        }

        return $names;
    }

    /**
     * Separators, not pairs a parser would keep: `a=1&&b=2` is three
     * separated runs and two fields, and this counts three. Over-counting
     * is the safe direction — the number bounds work, and a body cannot
     * hide pairs from it.
     */
    private static function countPairs(string $body): int
    {
        return $body === '' ? 0 : substr_count($body, FormPairs::SEPARATOR) + 1;
    }
}
