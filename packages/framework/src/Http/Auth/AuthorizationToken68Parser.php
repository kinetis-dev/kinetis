<?php

declare(strict_types=1);

namespace Kinetis\Http\Auth;

use InvalidArgumentException;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Parses an `Authorization: <scheme> <token68>` request header per RFC
 * 9110 §11.6.2 (`credentials = auth-scheme [ 1*SP ( token68 /
 * #auth-param ) ]`) for the `token68` form RFC 6750 §2.1 names
 * `b64token` (`1*( ALPHA / DIGIT / "-" / "." / "_" / "~" / "+" / "/" )
 * *"="`) — the one piece Kinetis\Auth\BearerAuthMiddleware
 * (kinetis/auth) and Kinetis\AuthJwt\JwtAuthMiddleware
 * (kinetis/auth-jwt) both need identically, extracted here so it exists
 * once rather than as two independently-drifting copies. The scheme is
 * the caller's, not the wire's: `Bearer` for both of those, `Token` for
 * an API that spells the same credential that way.
 *
 * `PSR-7`'s `getHeaderLine()` is not used here: it
 * comma-joins every `Authorization` line the request carries into one
 * string, which would silently turn two separate header
 * fields (a real ambiguity — RFC 9110 defines `Authorization` as a
 * single `credentials` value, not a combinable list) into what looks
 * like one value containing a comma. parse() instead reads the raw
 * `getHeader()` array and requires exactly one entry — zero or more
 * than one is a parse failure, the same as any other malformed input.
 *
 * $expectedScheme is trusted configuration, not input, and is held to
 * RFC 9110's `auth-scheme = token` grammar: anything else throws
 * InvalidArgumentException, and throws before the header is even read,
 * so a misconfigured caller fails identically whether the request
 * carries zero, one, or several `Authorization` lines. Untrusted input
 * never throws.
 *
 * That exception is the one way this class raises while a request or a
 * header value is still in its own stack frame, and a stack frame
 * carries the arguments it was called with, so any backtrace renders
 * them. Both parameters that carry a credential — parse()'s $request,
 * whose `Authorization` header holds one, and parseValue()'s $value,
 * which is that header — are `#[\SensitiveParameter]`, putting a
 * SensitiveParameterValue in the frame instead. $expectedScheme is not
 * marked: it is the caller's own configuration, it is what the message
 * names, and redacting it would hide the only thing that identifies the
 * failure.
 *
 * The wire scheme is matched case-insensitively (`bearer`/`BEARER`/
 * `Bearer` all match `Bearer`) per RFC 9110's own case-insensitive
 * scheme comparison; the separator between scheme and credential must
 * be one or more literal SP (0x20) characters — not a tab or other
 * whitespace, which RFC 9110's `1*SP` doesn't permit — but any count of
 * one or more is accepted. The credential itself must consist entirely
 * of `token68`/`b64token` characters, with `=` padding allowed only as
 * a trailing run — embedded or leading `=`, embedded or trailing
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
 * whatever accepts that credential (a `UserProviderInterface` lookup, a
 * JWT decoder).
 */
final class AuthorizationToken68Parser
{
    // \A/\z, not ^/$: PCRE's $ matches immediately before a single
    // trailing "\n" at the end of the subject, not only at the true end
    // — "Bearer token\n" would otherwise match, silently dropping the
    // newline and accepting an input this class documents as rejected.
    // \A/\z admit no such exception, matching only the true start/end of
    // the subject regardless of what it contains.
    private const string CREDENTIAL_PATTERN = '/\A[A-Za-z0-9\-._~+\/]+=*\z/';
    private const string SPLIT_PATTERN = '/\A(\S+)[ ]+(.+)\z/';

    // RFC 9110 §5.6.2 `token = 1*tchar`, the grammar `auth-scheme`
    // is defined as. Applied to $expectedScheme only: a wire scheme
    // outside it simply fails to equal the expected one.
    private const string SCHEME_PATTERN = '/\A[A-Za-z0-9!#$%&\'*+\-.^_`|~]+\z/';

    /**
     * @throws InvalidArgumentException when $expectedScheme is not an
     *                                  RFC 9110 auth-scheme token
     */
    public static function parse(
        #[\SensitiveParameter] ServerRequestInterface $request,
        string $expectedScheme,
    ): ?string {
        self::assertSchemeToken($expectedScheme);

        $values = $request->getHeader('Authorization');

        if (count($values) !== 1) {
            return null;
        }

        return self::parseValue($values[0], $expectedScheme);
    }

    /**
     * @throws InvalidArgumentException when $expectedScheme is not an
     *                                  RFC 9110 auth-scheme token
     */
    public static function parseValue(#[\SensitiveParameter] string $value, string $expectedScheme): ?string
    {
        self::assertSchemeToken($expectedScheme);

        if (!preg_match(self::SPLIT_PATTERN, $value, $matches)) {
            return null;
        }

        [, $scheme, $credential] = $matches;

        if (strcasecmp($scheme, $expectedScheme) !== 0) {
            return null;
        }

        if (!preg_match(self::CREDENTIAL_PATTERN, $credential)) {
            return null;
        }

        return $credential;
    }

    private static function assertSchemeToken(string $expectedScheme): void
    {
        if (preg_match(self::SCHEME_PATTERN, $expectedScheme) !== 1) {
            throw new InvalidArgumentException(
                "Expected auth-scheme must be an RFC 9110 token, got \"{$expectedScheme}\".",
            );
        }
    }
}
