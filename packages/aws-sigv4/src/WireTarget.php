<?php

declare(strict_types=1);

namespace Kinetis\AwsSigV4;

use SensitiveParameter;

/**
 * The one rule this package uses to turn a request target into the exact
 * representation that leaves the process, so the bytes that are signed
 * and the bytes that are sent are the same bytes.
 *
 * A signature covers a canonical path and query string. Anything that
 * rewrites either one after signing invalidates the signature at best,
 * and at worst moves the request somewhere the signature was never
 * checked against: an HTTP client that resolves `/a/../b` to `/b`, or
 * decodes `/%7Efoo` to `/~foo`, sends a target the signer never saw. The
 * transport underneath this package normalizes both ways, so the target
 * is put into that normal form here — before the origin is checked and
 * before anything is signed — rather than left for the transport to
 * change afterwards.
 *
 * The rule, applied to the path and the query string separately:
 *
 * - A percent escape standing for an unreserved character (`A-Z`,
 *   `a-z`, `0-9`, `-`, `.`, `_`, `~`) is decoded to that character;
 *   RFC 3986 calls the two spellings equivalent, so `%7Efoo` and `~foo`
 *   are one target and this picks the shorter one.
 * - Every other percent escape keeps its bytes and takes uppercase hex
 *   digits. `%2F` stays an encoded slash — decoding it would change
 *   which path segments exist.
 * - A character outside the unreserved set, the sub-delimiters, `:`,
 *   `@` and `/` is percent-encoded. That set is what both this package's
 *   PSR-7 implementation and the transport leave untouched, which is
 *   what makes the result a fixed point of both.
 * - The path then has its `.` and `..` segments removed (RFC 3986
 *   §5.2.4), after decoding, so an encoded `%2E%2E` segment is resolved
 *   here rather than by the transport. An empty path becomes `/`.
 *
 * Applying the rule to its own output changes nothing, which is the
 * property the signature depends on. The query string keeps its
 * parameter order and its duplicate keys; only its encoding is
 * normalized.
 *
 * @internal Used by SigV4SigningClient and TrustedOrigin.
 */
final class WireTarget
{
    /**
     * Unreserved characters, sub-delimiters, and the three path
     * characters RFC 3986 permits inside a segment. `%` is preserved so
     * an escape that survives decoding is not encoded a second time.
     */
    private const string SAFE_PATTERN = '{[^A-Za-z0-9\-._~!$&\'()*+,;=:@/%]+}';

    private const string UNRESERVED_PATTERN = '/^[A-Za-z0-9\-._~]$/';

    /**
     * Never `''`: an empty path is the origin-form target `/`, which is
     * what the transport puts on the request line.
     */
    public static function normalizePath(#[SensitiveParameter] string $path): string
    {
        $normalized = self::removeDotSegments(self::normalizeEncoding($path));

        return $normalized === '' ? '/' : $normalized;
    }

    /**
     * The encoding half of the rule on its own — what a query string
     * needs, and what a configured base path needs before its segments
     * can be read.
     *
     * Decodes the escapes that stand for unreserved characters, leaves
     * every other escape alone with uppercase hex digits, and encodes
     * whatever the transport would have encoded itself. The two passes
     * cannot fight each other: a decoded unreserved character is inside
     * the safe set, so the second pass never re-encodes what the first
     * one produced.
     *
     * The pattern matches exactly two hex digits, so an escape names one
     * byte and `rawurldecode()` of that escape returns it. Decoding the
     * escape keeps the byte range a property of the pattern: nothing has
     * to restate 0..255 for a reader or for a type checker.
     */
    public static function normalizeEncoding(#[SensitiveParameter] string $component): string
    {
        $decoded = preg_replace_callback(
            '/%([0-9A-Fa-f]{2})/',
            static function (array $match): string {
                $byte = rawurldecode($match[0]);

                return preg_match(self::UNRESERVED_PATTERN, $byte) === 1 ? $byte : '%' . strtoupper($match[1]);
            },
            $component,
        ) ?? $component;

        return preg_replace_callback(
            self::SAFE_PATTERN,
            static fn (array $match): string => rawurlencode($match[0]),
            $decoded,
        ) ?? $decoded;
    }

    /**
     * RFC 3986 §5.2.4, on a path that already has its escapes
     * normalized, so a segment spelled `%2E%2E` is removed here exactly
     * as a segment spelled `..` is.
     */
    private static function removeDotSegments(#[SensitiveParameter] string $path): string
    {
        $result = '';

        while ($path !== '' && $path !== '.' && $path !== '..') {
            if (str_starts_with($path, '../')) {
                $path = substr($path, 3);
            } elseif (str_starts_with($path, './')) {
                $path = substr($path, 2);
            } elseif ($path === '/.' || str_starts_with($path, '/./')) {
                $path = substr_replace($path, '/', 0, 3);
            } elseif ($path === '/..' || str_starts_with($path, '/../')) {
                $slash = strrpos($result, '/');
                $result = $slash === false || $slash === 0 ? '' : substr($result, 0, $slash);
                $path = substr_replace($path, '/', 0, 4);
            } else {
                $next = strpos($path, '/', 1);
                $length = $next === false ? \strlen($path) : $next;
                $result .= substr($path, 0, $length);
                $path = substr($path, $length);
            }
        }

        return $result;
    }
}
