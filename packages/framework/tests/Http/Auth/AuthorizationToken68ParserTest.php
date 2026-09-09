<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http\Auth;

use InvalidArgumentException;
use Kinetis\Http\Auth\AuthorizationToken68Parser;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SensitiveParameterValue;
use Throwable;

final class AuthorizationToken68ParserTest extends TestCase
{
    private const string CREDENTIAL = 'trace-secrecy-credential-must-never-leak';

    private const string INVALID_SCHEME = 'Bea rer';

    private string $ignoreArguments = '0';

    #[\Override]
    protected function setUp(): void
    {
        // A trace carries arguments only while this is off, and that is
        // the case the redaction exists for.
        $this->ignoreArguments = (string) ini_get('zend.exception_ignore_args');
        ini_set('zend.exception_ignore_args', '0');
    }

    #[\Override]
    protected function tearDown(): void
    {
        ini_set('zend.exception_ignore_args', $this->ignoreArguments);
    }

    /**
     * The full accepted/rejected grammar matrix, run against
     * parseValue() directly under two different expected schemes —
     * everything except the "how many Authorization header lines does
     * the request carry" structural check, which needs a real request
     * and is covered separately below.
     *
     * Both schemes run the identical matrix because the grammar is the
     * scheme's only variable: proving it for `Bearer` alone would leave
     * "the scheme is a parameter, not a hard-coded literal"
     * unproven, which is the whole reason this class takes one.
     *
     * @return iterable<string, array{string, string, string|null}>
     */
    public static function grammarCases(): iterable
    {
        foreach (['Bearer', 'Token'] as $scheme) {
            foreach (self::grammarCasesFor($scheme) as $name => $case) {
                yield "{$name} ({$scheme})" => $case;
            }
        }
    }

    /**
     * @return iterable<string, array{string, string, string|null}>
     */
    private static function grammarCasesFor(string $scheme): iterable
    {
        $lower = strtolower($scheme);
        $upper = strtoupper($scheme);

        // Scheme casing — case-insensitive per RFC 9110.
        yield 'lowercase wire scheme' => [$scheme, "{$lower} token", 'token'];
        yield 'uppercase wire scheme' => [$scheme, "{$upper} token", 'token'];
        yield 'lowercase expected scheme' => [$lower, "{$scheme} token", 'token'];
        yield 'uppercase expected scheme' => [$upper, "{$scheme} token", 'token'];
        yield 'canonical-case scheme' => [$scheme, "{$scheme} token", 'token'];

        // Separator: one or more literal SP is accepted; a tab is not.
        yield 'single SP separator' => [$scheme, "{$scheme} token", 'token'];
        yield 'two SP separator' => [$scheme, "{$scheme}  token", 'token'];
        yield 'many SP separator' => [$scheme, "{$scheme}     token", 'token'];
        yield 'tab separator' => [$scheme, "{$scheme}\ttoken", null];
        yield 'no separator at all' => [$scheme, "{$scheme}Token", null];

        // Empty / missing credential.
        yield 'empty token' => [$scheme, "{$scheme} ", null];
        yield 'scheme with no token' => [$scheme, $scheme, null];

        // Realistic credential shapes — base64url (JWT-style, dot-
        // separated), plain base64, and an opaque (non-base64) token —
        // all draw from the same accepted token68 alphabet, and all
        // come back as the exact bytes that followed the separator.
        yield 'JWT-shaped base64url token' => [
            $scheme,
            "{$scheme} eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiJ1c2VyLTQyIn0.dGVzdC1zaWduYXR1cmU",
            'eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiJ1c2VyLTQyIn0.dGVzdC1zaWduYXR1cmU',
        ];
        yield 'plain base64 token' => [$scheme, "{$scheme} YWJjZGVmZ2g=", 'YWJjZGVmZ2g='];
        yield 'opaque hex-style token' => [
            $scheme,
            "{$scheme} 4f3a9c1e8b2d4a6f9c0e1b3d5a7c9e1f",
            '4f3a9c1e8b2d4a6f9c0e1b3d5a7c9e1f',
        ];
        yield 'full token68 alphabet' => [$scheme, "{$scheme} -._~+/09azAZ", '-._~+/09azAZ'];

        // Padding: legal only as a trailing run; anywhere else is
        // rejected. No stricter base64-validity (length%4, max two `=`)
        // is imposed — RFC 6750's b64token grammar doesn't require it,
        // and an opaque token has no reason to satisfy it at all.
        yield 'no padding' => [$scheme, "{$scheme} YWJj", 'YWJj'];
        yield 'single trailing padding char' => [$scheme, "{$scheme} YQ=", 'YQ='];
        yield 'double trailing padding chars' => [$scheme, "{$scheme} YQ==", 'YQ=='];
        yield 'leading padding' => [$scheme, "{$scheme} =YQ", null];
        yield 'embedded padding' => [$scheme, "{$scheme} Y=Q", null];

        // Whitespace surrounding or embedded in the credential/header.
        yield 'trailing whitespace after credential' => [$scheme, "{$scheme} token ", null];
        yield 'leading whitespace before scheme' => [$scheme, " {$scheme} token", null];
        yield 'embedded whitespace within credential' => [$scheme, "{$scheme} to ken", null];

        // Comma — both a bare comma inside the credential and the
        // auth-param form RFC 9110 allows generically but RFC 6750
        // doesn't permit for a token68 credential.
        yield 'comma inside credential' => [$scheme, "{$scheme} abc,def", null];
        yield 'auth-param form' => [$scheme, "{$scheme} token, foo=\"bar\"", null];

        // A wire scheme that is not the expected one.
        yield 'Basic scheme' => [$scheme, 'Basic dXNlcjpwYXNz', null];
        yield 'Digest scheme' => [$scheme, 'Digest token', null];

        // Very long but syntactically valid input.
        yield 'very long valid token' => [$scheme, "{$scheme} " . str_repeat('a', 8192), str_repeat('a', 8192)];

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
        yield 'trailing LF' => [$scheme, "{$scheme} token\n", null];
        yield 'trailing CRLF' => [$scheme, "{$scheme} token\r\n", null];
        yield 'trailing CR' => [$scheme, "{$scheme} token\r", null];
        yield 'trailing NUL' => [$scheme, "{$scheme} token\0", null];
        yield 'embedded LF within credential' => [$scheme, "{$scheme} tok\nen", null];
        yield 'embedded CR within credential' => [$scheme, "{$scheme} tok\ren", null];
        yield 'embedded control character within credential' => [$scheme, "{$scheme} tok\x01en", null];
    }

    #[DataProvider('grammarCases')]
    public function test_parse_value_matches_the_grammar(
        string $expectedScheme,
        string $headerValue,
        ?string $expected,
    ): void {
        self::assertSame($expected, AuthorizationToken68Parser::parseValue($headerValue, $expectedScheme));
    }

    /**
     * The scheme decides the answer and nothing else does: one header
     * value, two expected schemes, one match each way. `Token` and
     * `Bearer` prefix the same credential differently, so a parser that
     * ignored its $expectedScheme would
     * pass every case in the matrix above and still be wrong here.
     *
     * @return iterable<string, array{string, string, string|null}>
     */
    public static function schemeDiscrimination(): iterable
    {
        yield 'Token header, Token expected' => ['Token', 'Token abc123', 'abc123'];
        yield 'Token header, Bearer expected' => ['Bearer', 'Token abc123', null];
        yield 'Bearer header, Bearer expected' => ['Bearer', 'Bearer abc123', 'abc123'];
        yield 'Bearer header, Token expected' => ['Token', 'Bearer abc123', null];
    }

    #[DataProvider('schemeDiscrimination')]
    public function test_only_the_expected_scheme_matches(
        string $expectedScheme,
        string $headerValue,
        ?string $expected,
    ): void {
        self::assertSame($expected, AuthorizationToken68Parser::parseValue($headerValue, $expectedScheme));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidExpectedSchemes(): iterable
    {
        yield 'empty' => [''];
        yield 'a single space' => [' '];
        yield 'trailing space' => ['Bearer '];
        yield 'leading space' => [' Bearer'];
        yield 'an embedded space' => ['Bea rer'];
        yield 'a tab' => ["Bearer\t"];
        yield 'a comma' => ['Bearer,'];
        yield 'a double quote' => ['Bea"rer'];
        yield 'a slash' => ['Bearer/1'];
        yield 'a newline' => ["Bearer\n"];
        yield 'a NUL byte' => ["Bearer\0"];
        yield 'a non-ASCII character' => ['Beärer'];
    }

    /**
     * $expectedScheme is trusted configuration, so an invalid one is a
     * programming error rather than a rejected credential.
     */
    #[DataProvider('invalidExpectedSchemes')]
    public function test_parse_value_throws_for_an_invalid_expected_scheme(string $expectedScheme): void
    {
        $this->expectException(InvalidArgumentException::class);

        AuthorizationToken68Parser::parseValue('Bearer token', $expectedScheme);
    }

    /**
     * @return iterable<string, array{ServerRequest}>
     */
    public static function requestsCarryingDifferentHeaderCounts(): iterable
    {
        $one = new ServerRequest('GET', '/', headers: ['Authorization' => 'Bearer token']);

        yield 'no Authorization header' => [new ServerRequest('GET', '/')];
        yield 'one Authorization header' => [$one];
        yield 'two Authorization headers' => [$one->withAddedHeader('Authorization', 'Bearer token-b')];
    }

    /**
     * A misconfigured scheme fails identically whatever the request
     * carries: the check runs before the header is read, so it can
     * never be masked by a request that would have been rejected on its
     * own anyway.
     */
    #[DataProvider('requestsCarryingDifferentHeaderCounts')]
    public function test_parse_throws_for_an_invalid_expected_scheme_whatever_the_request_carries(
        ServerRequest $request,
    ): void {
        $this->expectException(InvalidArgumentException::class);

        AuthorizationToken68Parser::parse($request, 'Bea rer');
    }

    public function test_parse_returns_the_credential_for_a_single_well_formed_header(): void
    {
        $request = new ServerRequest('GET', '/', headers: ['Authorization' => 'Token token']);

        self::assertSame('token', AuthorizationToken68Parser::parse($request, 'Token'));
    }

    public function test_parse_returns_null_when_the_authorization_header_is_absent(): void
    {
        $request = new ServerRequest('GET', '/');

        self::assertNull(AuthorizationToken68Parser::parse($request, 'Bearer'));
    }

    /**
     * Two separate Authorization header lines are ambiguous —
     * PSR-7's getHeaderLine() would comma-join them into one string that
     * looks like (but isn't) a single value; parse() reads the raw
     * header array instead and rejects anything but exactly one line.
     */
    public function test_parse_returns_null_for_duplicate_header_lines(): void
    {
        $request = new ServerRequest('GET', '/', headers: ['Authorization' => 'Bearer token-a']);
        $request = $request->withAddedHeader('Authorization', 'Bearer token-b');

        self::assertSame(['Bearer token-a', 'Bearer token-b'], $request->getHeader('Authorization'));
        self::assertNull(AuthorizationToken68Parser::parse($request, 'Bearer'));
    }

    public function test_parse_returns_null_for_two_identical_duplicate_header_lines(): void
    {
        // Even identical duplicate lines are still two
        // distinct header fields, not one — RFC 9110 defines
        // Authorization as a single credentials value, not a
        // combinable list the way e.g. Accept is.
        $request = new ServerRequest('GET', '/', headers: ['Authorization' => 'Bearer token']);
        $request = $request->withAddedHeader('Authorization', 'Bearer token');

        self::assertNull(AuthorizationToken68Parser::parse($request, 'Bearer'));
    }

    /**
     * A misconfigured scheme is the only way this class raises at all,
     * and it raises with the request still in parse()'s own frame —
     * where the `Authorization` header a backtrace would render is.
     */
    public function test_a_configuration_failure_hides_the_request_it_was_handed(): void
    {
        $request = new ServerRequest('GET', '/', headers: [
            'Authorization' => 'Bearer ' . self::CREDENTIAL,
        ]);

        try {
            AuthorizationToken68Parser::parse($request, self::INVALID_SCHEME);
            self::fail('Expected the configured scheme to be rejected.');
        } catch (InvalidArgumentException $e) {
            self::assertRedactedAt($e, 'parse');
        }
    }

    /**
     * The same failure reached through the other entry point, where the
     * header value itself — not a request wrapping it — is the argument
     * on the frame. parse() throws before it ever calls parseValue(),
     * so this is the only way that frame carries one.
     */
    public function test_a_configuration_failure_hides_the_header_value_it_was_handed(): void
    {
        try {
            AuthorizationToken68Parser::parseValue('Bearer ' . self::CREDENTIAL, self::INVALID_SCHEME);
            self::fail('Expected the configured scheme to be rejected.');
        } catch (InvalidArgumentException $e) {
            self::assertRedactedAt($e, 'parseValue');
        }
    }

    /**
     * Asserts that $frame is on the stack with an argument replaced,
     * that the credential appears in none of this class's own frames,
     * and that the configured scheme still does — it is the caller's own
     * configuration and the only thing that names the failure, so
     * redacting it would be a loss, not a protection.
     */
    private static function assertRedactedAt(Throwable $failure, string $frame): void
    {
        $owned = [];
        $redacted = [];

        foreach ($failure->getTrace() as $call) {
            if (($call['class'] ?? null) !== AuthorizationToken68Parser::class) {
                continue;
            }

            $arguments = (array) ($call['args'] ?? []);
            $owned[] = $arguments;

            if ($call['function'] === $frame) {
                $redacted = [...$redacted, ...array_filter(
                    $arguments,
                    static fn (mixed $argument): bool => $argument instanceof SensitiveParameterValue,
                )];
            }
        }

        self::assertNotEmpty($redacted, "{$frame}() carries no redacted argument.");
        self::assertStringNotContainsString(
            self::CREDENTIAL,
            print_r($owned, true),
            'The credential survived in this class\'s own frames.',
        );
        self::assertStringContainsString(
            self::INVALID_SCHEME,
            print_r($owned, true),
            'The configured scheme is not credential data and must stay readable.',
        );
    }
}
