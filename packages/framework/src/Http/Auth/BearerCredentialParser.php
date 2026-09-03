<?php

declare(strict_types=1);

namespace Kinetis\Http\Auth;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Parses an `Authorization: Bearer <token>` request header per RFC 7235
 * §2.1 (`credentials = auth-scheme [ 1*SP ( token68 / #auth-param ) ]`)
 * and RFC 6750 §2.1 (`b64token = 1*( ALPHA / DIGIT / "-" / "." / "_" /
 * "~" / "+" / "/" ) *"="`) — the one piece
 * Kinetis\Auth\BearerAuthMiddleware (kinetis/auth) and
 * Kinetis\AuthJwt\JwtAuthMiddleware (kinetis/auth-jwt) both need
 * identically, extracted here so it exists once rather than as two
 * independently-drifting copies.
 *
 * `PSR-7`'s `getHeaderLine()` is deliberately never used here: it
 * comma-joins every `Authorization` line the request carries into one
 * string, which would silently turn two genuinely separate header
 * fields (a real ambiguity — RFC 7235 defines `Authorization` as a
 * single `credentials` value, not a combinable list) into what looks
 * like one value containing a comma. parse() instead reads the raw
 * `getHeader()` array and requires exactly one entry — zero or more
 * than one is a parse failure, the same as any other malformed input.
 *
 * The auth-scheme is matched case-insensitively (`bearer`/`BEARER`/
 * `Bearer` all match) per RFC 7235's own case-insensitive scheme
 * comparison; the separator between scheme and credential must be one
 * or more literal SP (0x20) characters — not a tab or other whitespace,
 * which RFC 7235's `1*SP` doesn't permit — but any count of one or more
 * is accepted. The credential itself must consist entirely of
 * `token68`/`b64token` characters, with `=` padding allowed only as a
 * trailing run — embedded or leading `=`, embedded or trailing
 * whitespace anywhere in the credential, a comma, or any other
 * character outside that set is rejected. No length limit is imposed —
 * a long but otherwise well-formed credential is accepted.
 *
 * Whitespace around the *whole* header value (leading space before the
 * scheme, trailing space after the credential) is rejected outright
 * rather than trimmed — a deliberate choice, not an oversight: a
 * conformant HTTP layer has already stripped RFC 9110's optional
 * whitespace (OWS) from a field value before this ever runs, so
 * leading/trailing whitespace reaching here is either a non-conformant
 * upstream or a hand-built PSR-7 request, and this parser enforces the
 * strict grammar rather than re-normalizing input that should already
 * be clean. This falls out of the implementation without any special
 * case — the match is fully anchored to the true start and end of the
 * subject (`\A...\z`, not `^...$` — PCRE's `$` matches immediately
 * before a single trailing "\n", which `\z` never does), so a leading
 * space before the scheme, a trailing one after the credential, or a
 * trailing newline all simply fail to match — but it's stated here
 * because it's a real behavioral choice, not an accident of the regex.
 *
 * Never trims, decodes, or otherwise transforms the credential — the
 * exact bytes between the separator and the end of the header value are
 * returned unchanged (once validated), for the caller to pass to
 * whatever accepts a bearer token (a `UserProviderInterface` lookup, a
 * JWT decoder).
 */
final class BearerCredentialParser
{
    // \A/\z, not ^/$: PCRE's $ matches immediately before a single
    // trailing "\n" at the end of the subject, not only at the true end
    // — "Bearer token\n" would otherwise match, silently dropping the
    // newline and accepting an input this class documents as rejected.
    // \A/\z admit no such exception, matching only the true start/end of
    // the subject regardless of what it contains.
    private const string CREDENTIAL_PATTERN = '/\A[A-Za-z0-9\-._~+\/]+=*\z/';
    private const string SPLIT_PATTERN = '/\A(\S+)[ ]+(.+)\z/';

    public static function parse(ServerRequestInterface $request): ?string
    {
        $values = $request->getHeader('Authorization');

        if (count($values) !== 1) {
            return null;
        }

        return self::parseValue($values[0]);
    }

    public static function parseValue(string $value): ?string
    {
        if (!preg_match(self::SPLIT_PATTERN, $value, $matches)) {
            return null;
        }

        [, $scheme, $credential] = $matches;

        if (strcasecmp($scheme, 'Bearer') !== 0) {
            return null;
        }

        if (!preg_match(self::CREDENTIAL_PATTERN, $credential)) {
            return null;
        }

        return $credential;
    }
}
