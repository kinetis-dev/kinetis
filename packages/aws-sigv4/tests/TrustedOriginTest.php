<?php

declare(strict_types=1);

namespace Kinetis\AwsSigV4\Tests;

use Kinetis\AwsSigV4\Exception\SigningException;
use Kinetis\AwsSigV4\Exception\UntrustedOriginException;
use Kinetis\AwsSigV4\SigV4SigningClient;
use Kinetis\AwsSigV4\Tests\Support\FixedCredentialProvider;
use Kinetis\AwsSigV4\Tests\Support\RawUri;
use Kinetis\AwsSigV4\Tests\Support\RecordingTransport;
use Kinetis\AwsSigV4\Tests\Support\ThrowingCredentialProvider;
use Kinetis\AwsSigV4\Tests\Support\ThrowingStream;
use Nyholm\Psr7\Request;
use Nyholm\Psr7\Uri;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\UriInterface;

/**
 * The configured origin: what it accepts, what it rejects at
 * construction, and which request targets it lets through.
 */
final class TrustedOriginTest extends TestCase
{
    /**
     * @return iterable<string, array{origin: string, target: string, url: string}>
     */
    public static function canonicalOriginProvider(): iterable
    {
        yield 'mixed-case scheme and host' => [
            'origin' => 'HttpS://API.Example.COM',
            'target' => '/health',
            'url' => 'https://api.example.com/health',
        ];
        yield 'explicit default https port' => [
            'origin' => 'https://api.example.com:443',
            'target' => '/health',
            'url' => 'https://api.example.com/health',
        ];
        yield 'explicit default http port' => [
            'origin' => 'http://api.example.com:80',
            'target' => '/health',
            'url' => 'http://api.example.com/health',
        ];
        yield 'non-default port' => [
            'origin' => 'http://localhost:4566',
            'target' => '/health',
            'url' => 'http://localhost:4566/health',
        ];
        yield 'IPv4 host' => [
            'origin' => 'http://127.0.0.1:8080',
            'target' => '/health',
            'url' => 'http://127.0.0.1:8080/health',
        ];
        yield 'bracketed IPv6 host' => [
            'origin' => 'http://[2001:DB8::1]:8080',
            'target' => '/health',
            'url' => 'http://[2001:db8::1]:8080/health',
        ];
        yield 'expanded IPv6 loopback' => [
            'origin' => 'http://[0:0:0:0:0:0:0:1]:8080',
            'target' => '/health',
            'url' => 'http://[::1]:8080/health',
        ];
        yield 'base path' => [
            'origin' => 'https://api.example.com/prod',
            'target' => '/users',
            'url' => 'https://api.example.com/prod/users',
        ];
    }

    #[DataProvider('canonicalOriginProvider')]
    public function test_a_valid_origin_is_canonicalized_and_applied(string $origin, string $target, string $url): void
    {
        $transport = new RecordingTransport();

        self::clientFor($origin, $transport)->sendRequest(new Request('GET', $target));

        self::assertSame($url, $transport->urlOfCall(0));
    }

    /**
     * @return iterable<string, array{basePath: string, requestPath: string, expected: string}>
     */
    public static function pathJoiningProvider(): iterable
    {
        yield 'base without trailing slash, request with leading slash' => [
            'basePath' => '/prod', 'requestPath' => '/users', 'expected' => '/prod/users',
        ];
        yield 'base with trailing slash, request with leading slash' => [
            'basePath' => '/prod/', 'requestPath' => '/users', 'expected' => '/prod/users',
        ];
        // PSR-7 permits a relative-reference path with no leading slash.
        yield 'base without trailing slash, request without leading slash' => [
            'basePath' => '/prod', 'requestPath' => 'users', 'expected' => '/prod/users',
        ];
        yield 'base with trailing slash, request without leading slash' => [
            'basePath' => '/prod/', 'requestPath' => 'users', 'expected' => '/prod/users',
        ];
        yield 'no base path, request without leading slash' => [
            'basePath' => '', 'requestPath' => 'users', 'expected' => '/users',
        ];
        yield 'empty request path keeps the base path' => [
            'basePath' => '/prod/', 'requestPath' => '', 'expected' => '/prod',
        ];
        // An empty resolved path reaches the wire as "/": that is the
        // origin-form of an empty request target, not a joined segment.
        yield 'empty request path against a host-only origin' => [
            'basePath' => '', 'requestPath' => '', 'expected' => '/',
        ];
    }

    #[DataProvider('pathJoiningProvider')]
    public function test_base_path_and_request_path_are_joined_with_exactly_one_slash(
        string $basePath,
        string $requestPath,
        string $expected,
    ): void {
        $transport = new RecordingTransport();

        self::clientFor("https://api.example.com{$basePath}", $transport)
            ->sendRequest(new Request('GET', $requestPath));

        self::assertSame("https://api.example.com{$expected}", $transport->urlOfCall(0));
    }

    public function test_the_request_query_string_survives_origin_resolution(): void
    {
        $transport = new RecordingTransport();

        self::clientFor('https://api.example.com/prod', $transport)
            ->sendRequest(new Request('GET', 'users?active=true&page=2'));

        self::assertSame('https://api.example.com/prod/users?active=true&page=2', $transport->urlOfCall(0));
    }

    /**
     * An absolute target already naming the origin keeps the path it
     * came with rather than having the base path joined onto it a second
     * time — it must already lie under that base path, which
     * {@see WireTargetTest} covers.
     */
    public function test_an_absolute_on_origin_target_keeps_its_own_path(): void
    {
        $transport = new RecordingTransport();

        self::clientFor('https://api.example.com/prod', $transport)
            ->sendRequest(new Request('GET', 'https://api.example.com/prod/users'));

        self::assertSame('https://api.example.com/prod/users', $transport->urlOfCall(0));
    }

    /**
     * Each case names the message it must fail with, not merely that it
     * fails: the guards run in sequence over one string, and a later one
     * rejecting what an earlier one was there to catch would otherwise
     * read as the same pass. The message is the field and the rule.
     *
     * @return iterable<string, array{origin: string, message: string}>
     */
    public static function rejectedOriginProvider(): iterable
    {
        yield 'not absolute' => [
            'origin' => 'not-a-valid-uri', 'message' => SigningException::ORIGIN_NOT_ABSOLUTE,
        ];
        yield 'scheme-relative' => [
            'origin' => '//api.example.com', 'message' => SigningException::ORIGIN_NOT_ABSOLUTE,
        ];
        yield 'path only' => [
            'origin' => '/prod', 'message' => SigningException::ORIGIN_NOT_ABSOLUTE,
        ];
        yield 'unsupported scheme' => [
            'origin' => 'ftp://api.example.com', 'message' => SigningException::ORIGIN_UNSUPPORTED_SCHEME,
        ];
        yield 'userinfo' => [
            'origin' => 'https://user:pass@api.example.com',
            'message' => SigningException::ORIGIN_FORBIDDEN_COMPONENTS,
        ];
        yield 'query string' => [
            'origin' => 'https://api.example.com?x=1',
            'message' => SigningException::ORIGIN_FORBIDDEN_COMPONENTS,
        ];
        yield 'fragment' => [
            'origin' => 'https://api.example.com#section',
            'message' => SigningException::ORIGIN_FORBIDDEN_COMPONENTS,
        ];
        yield 'backslash authority' => [
            'origin' => 'https://api.example.com\\evil.com',
            'message' => SigningException::ORIGIN_AMBIGUOUS_CHARACTERS,
        ];
        yield 'control character' => [
            'origin' => "https://api.example.com/\x01",
            'message' => SigningException::ORIGIN_AMBIGUOUS_CHARACTERS,
        ];
        yield 'whitespace' => [
            'origin' => 'https://api.example.com /x',
            'message' => SigningException::ORIGIN_AMBIGUOUS_CHARACTERS,
        ];
        yield 'trailing newline' => [
            'origin' => "https://api.example.com\n",
            'message' => SigningException::ORIGIN_AMBIGUOUS_CHARACTERS,
        ];
        yield 'percent-encoded authority' => [
            'origin' => 'https://api.example.com%2Eevil.com',
            'message' => SigningException::ORIGIN_ENCODED_AUTHORITY,
        ];
        yield 'empty host' => [
            'origin' => 'https:///prod', 'message' => SigningException::ORIGIN_INVALID_HOST,
        ];
        yield 'host with an underscore' => [
            'origin' => 'https://api_example.com', 'message' => SigningException::ORIGIN_INVALID_HOST,
        ];
        yield 'host label starting with a dash' => [
            'origin' => 'https://-api.example.com', 'message' => SigningException::ORIGIN_INVALID_HOST,
        ];
        yield 'trailing dot host' => [
            'origin' => 'https://api.example.com.', 'message' => SigningException::ORIGIN_INVALID_HOST,
        ];
        yield 'IPv4 with a leading zero octet' => [
            'origin' => 'https://192.168.001.1', 'message' => SigningException::ORIGIN_INVALID_HOST,
        ];
        yield 'five-octet IPv4' => [
            'origin' => 'https://1.2.3.4.5', 'message' => SigningException::ORIGIN_INVALID_HOST,
        ];
        yield 'unterminated IPv6 bracket' => [
            'origin' => 'https://[2001:db8::1', 'message' => SigningException::ORIGIN_INVALID_HOST,
        ];
        // The bracket is missing and what precedes the final colon is a
        // whole address on its own, so an authority read without the
        // closing bracket parses as a host this origin never named.
        yield 'unterminated IPv6 bracket around a complete address' => [
            'origin' => 'https://[::1:', 'message' => SigningException::ORIGIN_INVALID_HOST,
        ];
        yield 'invalid IPv6' => [
            'origin' => 'https://[2001:db8:::1]', 'message' => SigningException::ORIGIN_INVALID_HOST,
        ];
        yield 'non-numeric port' => [
            'origin' => 'https://api.example.com:https', 'message' => SigningException::ORIGIN_INVALID_PORT,
        ];
        yield 'empty port' => [
            'origin' => 'https://api.example.com:', 'message' => SigningException::ORIGIN_INVALID_PORT,
        ];
        yield 'zero port' => [
            'origin' => 'https://api.example.com:0', 'message' => SigningException::ORIGIN_INVALID_PORT,
        ];
        yield 'port above the range' => [
            'origin' => 'https://api.example.com:65536', 'message' => SigningException::ORIGIN_INVALID_PORT,
        ];
        yield 'dot-dot base path' => [
            'origin' => 'https://api.example.com/prod/../admin',
            'message' => SigningException::ORIGIN_INVALID_PATH,
        ];
        yield 'dot base path' => [
            'origin' => 'https://api.example.com/prod/./x', 'message' => SigningException::ORIGIN_INVALID_PATH,
        ];
        yield 'encoded dot-dot base path' => [
            'origin' => 'https://api.example.com/prod/%2E%2E/admin',
            'message' => SigningException::ORIGIN_INVALID_PATH,
        ];
        yield 'encoded dot base path' => [
            'origin' => 'https://api.example.com/prod/%2e/x', 'message' => SigningException::ORIGIN_INVALID_PATH,
        ];
        yield 'malformed percent escape in the path' => [
            'origin' => 'https://api.example.com/pro%zz', 'message' => SigningException::ORIGIN_INVALID_PATH,
        ];
        yield 'truncated percent escape in the path' => [
            'origin' => 'https://api.example.com/prod%2', 'message' => SigningException::ORIGIN_INVALID_PATH,
        ];
    }

    #[DataProvider('rejectedOriginProvider')]
    public function test_an_invalid_origin_is_rejected_at_construction(string $origin, string $message): void
    {
        $this->expectException(SigningException::class);
        $this->expectExceptionMessage($message);

        self::clientFor($origin, new RecordingTransport());
    }

    /**
     * The ends of the accepted port range are inside it. The values just
     * outside — 0 and 65536 — are in {@see rejectedOriginProvider}, so
     * the two together pin the range rather than one side of it.
     */
    public function test_the_port_range_ends_are_accepted(): void
    {
        $transport = new RecordingTransport();

        self::clientFor('http://api.example.com:65535', $transport)
            ->sendRequest(new Request('GET', '/health'));

        self::assertSame('http://api.example.com:65535/health', $transport->urlOfCall(0));

        $lowest = new RecordingTransport();

        self::clientFor('http://api.example.com:1', $lowest)->sendRequest(new Request('GET', '/health'));

        self::assertSame('http://api.example.com:1/health', $lowest->urlOfCall(0));
    }

    /**
     * @return iterable<string, array{region: string, service: string, message: string}>
     */
    public static function rejectedNameProvider(): iterable
    {
        yield 'empty region' => [
            'region' => '', 'service' => 'es', 'message' => SigningException::INVALID_REGION,
        ];
        yield 'blank region' => [
            'region' => ' ', 'service' => 'es', 'message' => SigningException::INVALID_REGION,
        ];
        yield 'region with a slash' => [
            'region' => 'us-east-1/x', 'service' => 'es', 'message' => SigningException::INVALID_REGION,
        ];
        yield 'non-ASCII region' => [
            'region' => "us-east-1\u{00e9}", 'service' => 'es', 'message' => SigningException::INVALID_REGION,
        ];
        yield 'over-long region' => [
            'region' => str_repeat('a', 65), 'service' => 'es', 'message' => SigningException::INVALID_REGION,
        ];
        yield 'region starting with a dash' => [
            'region' => '-us-east-1', 'service' => 'es', 'message' => SigningException::INVALID_REGION,
        ];
        yield 'empty service' => [
            'region' => 'us-east-1', 'service' => '', 'message' => SigningException::INVALID_SERVICE,
        ];
        yield 'service with a newline' => [
            'region' => 'us-east-1', 'service' => "es\nx", 'message' => SigningException::INVALID_SERVICE,
        ];
        // A pattern anchored with "$" rather than "\z" accepts a final
        // newline, so a value that ends in one would be signed into the
        // credential scope string as if it were clean.
        yield 'region ending in a newline' => [
            'region' => "us-east-1\n", 'service' => 'es', 'message' => SigningException::INVALID_REGION,
        ];
        yield 'region ending in CRLF' => [
            'region' => "us-east-1\r\n", 'service' => 'es', 'message' => SigningException::INVALID_REGION,
        ];
        yield 'region ending in a carriage return' => [
            'region' => "us-east-1\r", 'service' => 'es', 'message' => SigningException::INVALID_REGION,
        ];
        yield 'service ending in a newline' => [
            'region' => 'us-east-1', 'service' => "es\n", 'message' => SigningException::INVALID_SERVICE,
        ];
        yield 'service ending in CRLF' => [
            'region' => 'us-east-1', 'service' => "es\r\n", 'message' => SigningException::INVALID_SERVICE,
        ];
        yield 'service ending in a carriage return' => [
            'region' => 'us-east-1', 'service' => "es\r", 'message' => SigningException::INVALID_SERVICE,
        ];
    }

    #[DataProvider('rejectedNameProvider')]
    public function test_an_invalid_region_or_service_is_rejected_at_construction(
        string $region,
        string $service,
        string $message,
    ): void {
        $this->expectException(SigningException::class);
        $this->expectExceptionMessage($message);

        new SigV4SigningClient(
            'https://api.example.com',
            $region,
            $service,
            FixedCredentialProvider::example(),
            null,
            new RecordingTransport()->asTransport(),
        );
    }

    /**
     * @return iterable<string, list<string>>
     */
    public static function untrustedTargetProvider(): iterable
    {
        yield 'different host' => ['https://evil.example.com/users'];
        yield 'different port' => ['https://api.example.com:8443/users'];
        yield 'https to http downgrade' => ['http://api.example.com/users'];
        yield 'network-path reference' => ['//evil.example.com/users'];
        yield 'userinfo' => ['https://user:pass@api.example.com/users'];
        yield 'subdomain of the origin' => ['https://api.example.com.evil.example.com/users'];
        yield 'scheme with no host' => ['mailto:ops@example.com'];
    }

    #[DataProvider('untrustedTargetProvider')]
    public function test_an_off_origin_target_is_rejected(string $target): void
    {
        $this->assertTargetRejected(new Uri($target));
    }

    /**
     * @return iterable<string, array{target: UriInterface}>
     */
    public static function ambiguousTargetProvider(): iterable
    {
        yield 'backslash in the path' => [
            'target' => new RawUri('https', '', 'api.example.com', null, '/\\evil.example.com/x'),
        ];
        yield 'control character in the path' => [
            'target' => new RawUri('https', '', 'api.example.com', null, "/us\x01ers"),
        ];
        yield 'control character in the query' => [
            'target' => new RawUri('https', '', 'api.example.com', null, '/users', "a=\x00b"),
        ];
        yield 'percent-encoded authority' => [
            'target' => new RawUri('https', '', 'api%2eexample.com', null, '/users'),
        ];
        yield 'userinfo alongside the right host' => [
            'target' => new RawUri('https', 'user:pass', 'api.example.com', null, '/users'),
        ];
        yield 'port outside the valid range' => [
            'target' => new RawUri('https', '', 'api.example.com', 99999, '/users'),
        ];
        yield 'malformed percent escape in the path' => [
            'target' => new RawUri('https', '', 'api.example.com', null, '/us%zzers'),
        ];
        yield 'truncated percent escape in the query' => [
            'target' => new RawUri('https', '', 'api.example.com', null, '/users', 'q=%2'),
        ];
        // The path's own escape is truncated; the query's leading hex
        // digits would complete it only if the two were read as one
        // string, which is why each is asked on its own.
        yield 'a dangling escape in the path that the query would complete' => [
            'target' => new RawUri('https', '', 'api.example.com', null, '/users%', 'ab=1'),
        ];
    }

    /**
     * A target a PSR-7 implementation passed through unnormalized — see
     * {@see RawUri} for why one has to.
     */
    #[DataProvider('ambiguousTargetProvider')]
    public function test_an_ambiguous_target_is_rejected(UriInterface $target): void
    {
        $this->assertTargetRejected($target);
    }

    /**
     * @return iterable<string, array{origin: string, target: UriInterface, url: string}>
     */
    public static function onOriginSpellingProvider(): iterable
    {
        yield 'uppercase scheme' => [
            'origin' => 'https://api.example.com',
            'target' => new RawUri('HTTPS', '', 'api.example.com', null, '/users'),
            'url' => 'https://api.example.com/users',
        ];
        yield 'uppercase host' => [
            'origin' => 'https://api.example.com',
            'target' => new RawUri('https', '', 'API.Example.COM', null, '/users'),
            'url' => 'https://api.example.com/users',
        ];
        yield 'uppercase scheme and host' => [
            'origin' => 'https://api.example.com',
            'target' => new RawUri('HTTPS', '', 'API.EXAMPLE.COM', null, '/users'),
            'url' => 'https://api.example.com/users',
        ];
        yield 'IPv6 host in another spelling' => [
            'origin' => 'https://[::1]',
            'target' => new RawUri('https', '', '[0:0:0:0:0:0:0:1]', null, '/users'),
            'url' => 'https://[::1]/users',
        ];
        // The spelling that varies is the configured origin's. It is
        // canonicalized once, at construction, so the comparison has a
        // single form on both sides rather than a case-folding rule.
        yield 'uppercase host on the origin' => [
            'origin' => 'https://API.Example.COM',
            'target' => new RawUri('https', '', 'api.example.com', null, '/users'),
            'url' => 'https://api.example.com/users',
        ];
    }

    /**
     * Scheme and host are case-insensitive and an IPv6 address has more
     * than one spelling, so a target a PSR-7 implementation normalized
     * neither of — {@see RawUri} — still names the configured origin,
     * and so does a target spelled unlike the origin it names. Both
     * sides are compared in canonical form, and the wire URL is the
     * origin's own however either was spelled.
     */
    #[DataProvider('onOriginSpellingProvider')]
    public function test_an_on_origin_target_matches_however_it_is_spelled(
        string $origin,
        UriInterface $target,
        string $url,
    ): void {
        $transport = new RecordingTransport();

        self::clientFor($origin, $transport)->sendRequest(new Request('GET', $target));

        self::assertSame(1, $transport->callCount());
        self::assertSame($url, $transport->urlOfCall(0));
    }

    private function assertTargetRejected(UriInterface $target): void
    {
        $transport = new RecordingTransport();
        $client = self::clientFor('https://api.example.com', $transport);

        $this->expectException(UntrustedOriginException::class);

        try {
            $client->sendRequest(new Request('GET', $target));
        } finally {
            self::assertSame(0, $transport->callCount());
        }
    }

    /**
     * An http origin does not accept an https target either: the check
     * is equality, not a minimum.
     */
    public function test_an_upgrade_away_from_an_http_origin_is_rejected(): void
    {
        $transport = new RecordingTransport();
        $client = self::clientFor('http://localhost:4566', $transport);

        $this->expectException(UntrustedOriginException::class);

        $client->sendRequest(new Request('GET', 'https://localhost:4566/health'));
    }

    /**
     * The rejection happens before the credential provider is called and
     * before the body is read, so a provider that would throw and a body
     * that would throw both stay untouched.
     */
    public function test_a_rejected_target_reads_no_credentials_body_or_transport(): void
    {
        $transport = new RecordingTransport();
        $client = new SigV4SigningClient(
            'https://api.example.com',
            'us-east-1',
            'es',
            new ThrowingCredentialProvider(),
            null,
            $transport->asTransport(),
        );

        $request = new Request('GET', 'https://evil.example.com/users', [], new ThrowingStream());

        try {
            $client->sendRequest($request);

            self::fail('Expected an UntrustedOriginException to be thrown.');
        } catch (UntrustedOriginException $e) {
            self::assertSame(UntrustedOriginException::MESSAGE, $e->getMessage());
            self::assertSame($request, $e->getRequest());
            self::assertNull($e->getPrevious());
            self::assertSame(0, $transport->callCount());
        }
    }

    private static function clientFor(string $origin, RecordingTransport $transport): SigV4SigningClient
    {
        return new SigV4SigningClient(
            $origin,
            'us-east-1',
            'es',
            FixedCredentialProvider::example(),
            null,
            $transport->asTransport(),
        );
    }
}
