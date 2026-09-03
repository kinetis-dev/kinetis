<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http\Auth;

use Kinetis\Http\Auth\BearerCredentialParser;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BearerCredentialParserTest extends TestCase
{
    /**
     * The full accepted/rejected grammar matrix, run against
     * parseValue() directly — everything except the "how many
     * Authorization header lines does the request carry" structural
     * check, which needs a real request and is covered separately
     * below.
     *
     * @return iterable<string, array{string, string|null}>
     */
    public static function grammarCases(): iterable
    {
        // Scheme casing — case-insensitive per RFC 7235.
        yield 'lowercase scheme' => ['bearer token', 'token'];
        yield 'uppercase scheme' => ['BEARER token', 'token'];
        yield 'mixed-case scheme' => ['BeArEr token', 'token'];
        yield 'canonical-case scheme' => ['Bearer token', 'token'];

        // Separator: one or more literal SP is accepted; a tab is not.
        yield 'single SP separator' => ['Bearer token', 'token'];
        yield 'two SP separator' => ['Bearer  token', 'token'];
        yield 'many SP separator' => ['Bearer     token', 'token'];
        yield 'tab separator' => ["Bearer\ttoken", null];
        yield 'no separator at all' => ['BearerToken', null];

        // Empty / missing credential.
        yield 'empty token' => ['Bearer ', null];
        yield 'scheme with no token' => ['Bearer', null];

        // Realistic credential shapes — base64url (JWT-style, dot-
        // separated), plain base64, and an opaque (non-base64) token —
        // all draw from the same accepted token68 alphabet.
        yield 'JWT-shaped base64url token' => [
            'Bearer eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiJ1c2VyLTQyIn0.dGVzdC1zaWduYXR1cmU',
            'eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiJ1c2VyLTQyIn0.dGVzdC1zaWduYXR1cmU',
        ];
        yield 'plain base64 token' => ['Bearer YWJjZGVmZ2g=', 'YWJjZGVmZ2g='];
        yield 'opaque hex-style token' => ['Bearer 4f3a9c1e8b2d4a6f9c0e1b3d5a7c9e1f', '4f3a9c1e8b2d4a6f9c0e1b3d5a7c9e1f'];
        yield 'full token68 alphabet' => ['Bearer -._~+/09azAZ', '-._~+/09azAZ'];

        // Padding: legal only as a trailing run; anywhere else is
        // rejected. No stricter base64-validity (length%4, max two `=`)
        // is imposed — RFC 6750's b64token grammar doesn't require it,
        // and an opaque token has no reason to satisfy it at all.
        yield 'no padding' => ['Bearer YWJj', 'YWJj'];
        yield 'single trailing padding char' => ['Bearer YQ=', 'YQ='];
        yield 'double trailing padding chars' => ['Bearer YQ==', 'YQ=='];
        yield 'leading padding' => ['Bearer =YQ', null];
        yield 'embedded padding' => ['Bearer Y=Q', null];

        // Whitespace surrounding or embedded in the credential/header.
        yield 'trailing whitespace after credential' => ['Bearer token ', null];
        yield 'leading whitespace before scheme' => [' Bearer token', null];
        yield 'embedded whitespace within credential' => ['Bearer to ken', null];

        // Comma — both a bare comma inside the credential and the
        // auth-param form RFC 7235 allows generically but RFC 6750
        // doesn't permit for Bearer.
        yield 'comma inside credential' => ['Bearer abc,def', null];
        yield 'auth-param form' => ['Bearer token, foo="bar"', null];

        // Non-Bearer scheme.
        yield 'Basic scheme' => ['Basic dXNlcjpwYXNz', null];
        yield 'Digest scheme' => ['Digest token', null];

        // Very long but syntactically valid input.
        yield 'very long valid token' => ['Bearer ' . str_repeat('a', 8192), str_repeat('a', 8192)];

        // A trailing "\n" is a real edge case worth its own coverage:
        // PCRE's $ matches immediately before a single trailing newline
        // at the end of the subject, not only at the true end — a
        // pattern anchored with $ (rather than \z, which this class
        // uses) would silently accept this and drop the newline from
        // the returned credential rather than rejecting the input
        // outright. CRLF, a bare CR, NUL, and an embedded line break are
        // all covered too, even though none of them are affected by
        // that specific PCRE quirk — proving each is independently
        // rejected, not just assumed to be.
        yield 'trailing LF' => ["Bearer token\n", null];
        yield 'trailing CRLF' => ["Bearer token\r\n", null];
        yield 'trailing CR' => ["Bearer token\r", null];
        yield 'trailing NUL' => ["Bearer token\0", null];
        yield 'embedded LF within credential' => ["Bearer tok\nen", null];
        yield 'embedded CR within credential' => ["Bearer tok\ren", null];
        yield 'embedded control character within credential' => ["Bearer tok\x01en", null];
    }

    #[DataProvider('grammarCases')]
    public function test_parse_value_matches_the_grammar(string $headerValue, ?string $expected): void
    {
        self::assertSame($expected, BearerCredentialParser::parseValue($headerValue));
    }

    public function test_parse_returns_the_credential_for_a_single_well_formed_header(): void
    {
        $request = new ServerRequest('GET', '/', headers: ['Authorization' => 'Bearer token']);

        self::assertSame('token', BearerCredentialParser::parse($request));
    }

    public function test_parse_returns_null_when_the_authorization_header_is_absent(): void
    {
        $request = new ServerRequest('GET', '/');

        self::assertNull(BearerCredentialParser::parse($request));
    }

    /**
     * Two genuinely separate Authorization header lines are ambiguous —
     * PSR-7's getHeaderLine() would comma-join them into one string that
     * looks like (but isn't) a single value; parse() reads the raw
     * header array instead and rejects anything but exactly one line.
     */
    public function test_parse_returns_null_for_duplicate_header_lines(): void
    {
        $request = new ServerRequest('GET', '/', headers: ['Authorization' => 'Bearer token-a']);
        $request = $request->withAddedHeader('Authorization', 'Bearer token-b');

        self::assertSame(['Bearer token-a', 'Bearer token-b'], $request->getHeader('Authorization'));
        self::assertNull(BearerCredentialParser::parse($request));
    }

    public function test_parse_returns_null_for_two_identical_duplicate_header_lines(): void
    {
        // Even genuinely identical duplicate lines are still two
        // distinct header fields, not one — RFC 7235 defines
        // Authorization as a single credentials value, not a
        // combinable list the way e.g. Accept is.
        $request = new ServerRequest('GET', '/', headers: ['Authorization' => 'Bearer token']);
        $request = $request->withAddedHeader('Authorization', 'Bearer token');

        self::assertNull(BearerCredentialParser::parse($request));
    }
}
