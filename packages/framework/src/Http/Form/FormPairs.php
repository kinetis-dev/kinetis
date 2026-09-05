<?php

declare(strict_types=1);

namespace Kinetis\Http\Form;

use Kinetis\Http\Form\Exception\FormParserConfigurationException;

/**
 * The one place a form body reaches `parse_str()`, and the `&`
 * separator contract it reaches it under.
 *
 * `parse_str()` does not split on `&`. It splits on whatever
 * `arg_separator.input` names, which is a set of characters and defaults
 * to `&` without being fixed at it. Everything around it here owns `&`:
 * {@see UrlEncodedForm} splits a raw body on `&` to count its pairs and
 * read its names, and {@see MultipartFormBuilder} joins the names it
 * encoded with `&`. A runtime naming anything else parses a different
 * form from the one that was measured, in both directions:
 *
 * - Named `;`, a body of `a=1&b=2&…` is one pair to the parser and every
 *   pair to the preflight — so a form the counts accepted collapses into
 *   one field whose value is the rest of the body.
 * - Named `&;` — a set, which this setting allows — a body of
 *   `a=1;b=2;…` is one pair to the preflight and as many as it likes to
 *   the parser, which is exactly enough to walk past `MAX_INPUT_VARS`
 *   and then be cut back to this runtime's own `max_input_vars` with a
 *   warning nobody sees.
 *
 * Both end the same way: a handler receiving a form that is complete to
 * every check that ran and is not what the client sent. So the separator
 * is a requirement rather than a variable — anything but exactly `&` is
 * refused here, on every runtime, before `parse_str()` consumes a pair
 * and before a handler is handed a form. A multipart body reaches this
 * parse through an envelope {@see MultipartEnvelope} has validated and
 * the active multipart parser has expanded into parts; the parse
 * refused is the one those part names are joined for.
 *
 * The value is read immediately before the parse that depends on it, and
 * that is the value the parse runs under: `arg_separator.input` is
 * `PHP_INI_PERDIR`, so `ini_set()` cannot move it and no request can
 * change what the next one is parsed with.
 */
final class FormPairs
{
    /** The separator this framework splits on, counts and joins with. */
    public const string SEPARATOR = '&';

    /**
     * @param string $pairs a {@see SEPARATOR}-separated `name=value`
     *     list — a raw url-encoded request body, or one
     *     {@see MultipartFormBuilder} built out of part names it encoded
     *     itself
     * @return array<array-key, mixed>
     */
    public static function parse(string $pairs): array
    {
        $configured = ini_get('arg_separator.input');

        if ($configured !== self::SEPARATOR) {
            throw FormParserConfigurationException::unownedInputSeparator(is_string($configured) ? $configured : '');
        }

        parse_str($pairs, $fields);

        return $fields;
    }
}
