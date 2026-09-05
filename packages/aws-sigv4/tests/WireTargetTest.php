<?php

declare(strict_types=1);

namespace Kinetis\AwsSigV4\Tests;

use Kinetis\AwsSigV4\Exception\UntrustedOriginException;
use Kinetis\AwsSigV4\SigV4SigningClient;
use Kinetis\AwsSigV4\Tests\Support\FixedCredentialProvider;
use Kinetis\AwsSigV4\Tests\Support\RawUri;
use Kinetis\AwsSigV4\Tests\Support\RecordingTransport;
use Nyholm\Psr7\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What is signed is what is sent.
 *
 * A signature covers a path and a query string, so anything that
 * rewrites either one after signing sends a target the signer never saw:
 * a client that decodes `/%7Efoo` to `/~foo` or resolves `/a/../b` to
 * `/b` invalidates the signature at best, and at worst moves the request
 * somewhere the origin and base-path checks were never applied. The
 * target is put into its wire form before both checks and before
 * signing, so the transport underneath has nothing left to change.
 *
 * Every assertion below reads the Symfony transport underneath the owned
 * PSR-18 boundary — the last place the request exists before it leaves
 * the process.
 */
final class WireTargetTest extends TestCase
{
    private const string ORIGIN = 'https://example.amazonaws.com';

    /**
     * The date of AWS's own published SigV4 test vectors — see
     * {@see SigV4SigningClientTest::test_matches_the_aws_published_get_vanilla_test_vector},
     * which pins the credentials, region and service alongside it.
     */
    private const string VECTOR_DATE = '2015-08-30T12:36:00Z';

    /**
     * AWS's ground truth for "get-vanilla": a GET on the path `/`, no
     * query string, no body. Every target below that names the root path
     * once normalized produces that same canonical request, so it has to
     * produce this same signature — which is what makes the assertion
     * evidence about the target that was signed rather than a
     * restatement of what this package computed.
     */
    private const string VANILLA_AUTHORIZATION
        = 'AWS4-HMAC-SHA256 Credential=AKIDEXAMPLE/20150830/us-east-1/service/aws4_request, '
        . 'SignedHeaders=host;x-amz-date, '
        . 'Signature=5fa00fa31553b73ebf1942676e86291e8372ff2a2260956d9b8aae1d763fbf31';

    /**
     * @return iterable<string, array{target: string}>
     */
    public static function rootPathProvider(): iterable
    {
        yield 'literal dot-dot segment' => ['target' => '/example/..'];
        yield 'two literal dot-dot segments' => ['target' => '/example1/example2/../..'];
        yield 'literal dot segment' => ['target' => '/./'];
        yield 'encoded dot segment' => ['target' => '/%2E/'];
        yield 'encoded dot-dot segment' => ['target' => '/example/%2E%2E'];
        yield 'lowercase encoded dot-dot segment' => ['target' => '/example/%2e%2e'];
        yield 'empty path' => ['target' => ''];
        yield 'absolute target with a dot-dot segment' => ['target' => self::ORIGIN . '/example/..'];
    }

    /**
     * Each of these names the root path once it is in wire form, so the
     * URL the transport was handed has to be the root path and the
     * signature over it has to be AWS's published one for the root path.
     * A signature computed over `/example/..` while `/` went out fails
     * both halves.
     */
    #[DataProvider('rootPathProvider')]
    public function test_a_target_naming_the_root_path_is_signed_as_the_root_path(string $target): void
    {
        $transport = new RecordingTransport();

        self::vectorClient($transport)->sendRequest(new Request('GET', $target));

        self::assertSame('https://example.amazonaws.com/', $transport->urlOfCall(0));
        self::assertSame(self::VANILLA_AUTHORIZATION, $transport->headerLineOfCall(0, 'Authorization'));
    }

    /**
     * @return iterable<string, array{target: string, url: string}>
     */
    public static function normalizedTargetProvider(): iterable
    {
        yield 'encoded tilde' => [
            'target' => '/%7Efoo',
            'url' => 'https://example.amazonaws.com/~foo',
        ];
        yield 'the rest of the unreserved set, encoded' => [
            'target' => '/%61%42%310%2D%2E%5F%7E',
            'url' => 'https://example.amazonaws.com/aB10-._~',
        ];
        yield 'an encoded slash stays encoded rather than opening a segment' => [
            'target' => '/a%2Fb',
            'url' => 'https://example.amazonaws.com/a%2Fb',
        ];
        yield 'a lowercase escape takes uppercase hex' => [
            'target' => '/a%2fb%3ac',
            'url' => 'https://example.amazonaws.com/a%2Fb%3Ac',
        ];
        yield 'dot segments around a real one' => [
            'target' => '/a/./b/../c',
            'url' => 'https://example.amazonaws.com/a/c',
        ];
        yield 'a dot segment before the last one' => [
            'target' => '/a/./b',
            'url' => 'https://example.amazonaws.com/a/b',
        ];
        yield 'a trailing dot segment keeps the slash it stands on' => [
            'target' => '/a/.',
            'url' => 'https://example.amazonaws.com/a/',
        ];
        yield 'an encoded dot-dot segment cannot climb past the root' => [
            'target' => '/%2E%2E/%2E%2E/etc',
            'url' => 'https://example.amazonaws.com/etc',
        ];
        yield 'query escapes are normalized too' => [
            'target' => '/x?q=%7Ea%2Fb',
            'url' => 'https://example.amazonaws.com/x?q=~a%2Fb',
        ];
        yield 'query parameter order and duplicate keys survive' => [
            'target' => '/x?b=2&a=1&b=3',
            'url' => 'https://example.amazonaws.com/x?b=2&a=1&b=3',
        ];
        yield 'a fragment never reaches the wire' => [
            'target' => '/x#section',
            'url' => 'https://example.amazonaws.com/x',
        ];
        yield 'an absolute target is normalized like a relative one' => [
            'target' => self::ORIGIN . '/a/%2E%2E/b',
            'url' => 'https://example.amazonaws.com/b',
        ];
    }

    #[DataProvider('normalizedTargetProvider')]
    public function test_a_target_reaches_the_transport_in_wire_form(string $target, string $url): void
    {
        $transport = new RecordingTransport();

        self::vectorClient($transport)->sendRequest(new Request('GET', $target));

        self::assertSame($url, $transport->urlOfCall(0));
    }

    /**
     * The wire form is a fixed point, and every spelling of one target
     * signs identically: sending the recorded URL back through the same
     * client produces that same URL and that same `Authorization` value.
     * Were the transport still rewriting the URL it was handed, the two
     * signatures would cover different paths and differ.
     */
    #[DataProvider('normalizedTargetProvider')]
    public function test_signing_the_recorded_wire_target_reproduces_the_signature(
        string $target,
        string $url,
    ): void {
        $first = new RecordingTransport();
        self::vectorClient($first)->sendRequest(new Request('GET', $target));

        $replay = new RecordingTransport();
        self::vectorClient($replay)->sendRequest(new Request('GET', $first->urlOfCall(0)));

        self::assertSame($url, $replay->urlOfCall(0));
        self::assertSame(
            $first->headerLineOfCall(0, 'Authorization'),
            $replay->headerLineOfCall(0, 'Authorization'),
        );
    }

    /**
     * @return iterable<string, array{path: string, query: string, url: string}>
     */
    public static function rawComponentProvider(): iterable
    {
        yield 'a space in the path' => [
            'path' => '/a b',
            'query' => '',
            'url' => 'https://example.amazonaws.com/a%20b',
        ];
        yield 'characters outside the safe set' => [
            'path' => '/a|b"c',
            'query' => '',
            'url' => 'https://example.amazonaws.com/a%7Cb%22c',
        ];
        yield 'a space in the query' => [
            'path' => '/x',
            'query' => 'q=a b',
            'url' => 'https://example.amazonaws.com/x?q=a%20b',
        ];
        yield 'an already-safe target is left alone' => [
            'path' => '/a-b_c~d.e',
            'query' => 'q=1&r=2',
            'url' => 'https://example.amazonaws.com/a-b_c~d.e?q=1&r=2',
        ];
    }

    /**
     * PSR-7 requires no encoding of its own, so a target can arrive
     * carrying characters that have no place in a request line — see
     * {@see RawUri}. They are encoded here, before signing, rather than
     * by the transport afterwards, which would leave the signature
     * covering the raw bytes and the wire carrying the encoded ones.
     */
    #[DataProvider('rawComponentProvider')]
    public function test_a_raw_target_is_encoded_before_it_is_signed(
        string $path,
        string $query,
        string $url,
    ): void {
        $transport = new RecordingTransport();

        self::vectorClient($transport)->sendRequest(new Request(
            'GET',
            new RawUri('https', '', 'example.amazonaws.com', null, $path, $query),
        ));

        self::assertSame($url, $transport->urlOfCall(0));
    }

    /**
     * @return iterable<string, array{origin: string, target: string, url: string}>
     */
    public static function authorityProvider(): iterable
    {
        yield 'mixed-case host' => [
            'origin' => 'https://api.example.com',
            'target' => 'https://API.Example.COM/users',
            'url' => 'https://api.example.com/users',
        ];
        yield 'explicit default port' => [
            'origin' => 'https://api.example.com',
            'target' => 'https://api.example.com:443/users',
            'url' => 'https://api.example.com/users',
        ];
        yield 'expanded IPv6 loopback' => [
            'origin' => 'http://[::1]:8080',
            'target' => 'http://[0:0:0:0:0:0:0:1]:8080/users',
            'url' => 'http://[::1]:8080/users',
        ];
    }

    /**
     * Origin containment survives normalization because the authority is
     * not carried over from the target at all: the URI that is signed and
     * sent is built from the origin's own canonical scheme, host and
     * port, however the caller spelled the one it matched.
     */
    #[DataProvider('authorityProvider')]
    public function test_the_wire_authority_is_the_origins_own(string $origin, string $target, string $url): void
    {
        $transport = new RecordingTransport();

        self::vectorClient($transport, $origin)->sendRequest(new Request('GET', $target));

        self::assertSame($url, $transport->urlOfCall(0));
    }

    /**
     * @return iterable<string, array{target: string, url: string}>
     */
    public static function basePathProvider(): iterable
    {
        yield 'relative target' => [
            'target' => 'users',
            'url' => 'https://api.example.com/prod/users',
        ];
        yield 'relative target with an interior dot-dot segment' => [
            'target' => 'v1/../users',
            'url' => 'https://api.example.com/prod/users',
        ];
        yield 'absolute target under the base path' => [
            'target' => 'https://api.example.com/prod/users',
            'url' => 'https://api.example.com/prod/users',
        ];
        yield 'an encoded target compares in the same form as the base path' => [
            'target' => '/%75sers',
            'url' => 'https://api.example.com/prod/users',
        ];
    }

    #[DataProvider('basePathProvider')]
    public function test_a_target_under_the_base_path_is_sent_under_it(string $target, string $url): void
    {
        $transport = new RecordingTransport();

        self::vectorClient($transport, 'https://api.example.com/prod')
            ->sendRequest(new Request('GET', $target));

        self::assertSame($url, $transport->urlOfCall(0));
    }

    /**
     * @return iterable<string, array{target: string}>
     */
    public static function escapedBasePathProvider(): iterable
    {
        yield 'relative dot-dot segment' => ['target' => '../admin'];
        yield 'relative encoded dot-dot segment' => ['target' => '%2E%2E/admin'];
        yield 'rooted dot-dot segment' => ['target' => '/../admin'];
        yield 'absolute dot-dot segment' => ['target' => 'https://api.example.com/prod/../admin'];
        yield 'absolute target beside the base path' => ['target' => 'https://api.example.com/admin'];
        yield 'absolute target sharing the base path as a prefix' => [
            'target' => 'https://api.example.com/production/admin',
        ];
        yield 'absolute root' => ['target' => 'https://api.example.com/'];
    }

    /**
     * The base path binds every target, relative or absolute, and it is
     * checked in wire form: a path that leaves it only once the
     * transport resolves `..` would otherwise be signed for one place
     * and sent to another.
     */
    #[DataProvider('escapedBasePathProvider')]
    public function test_a_target_leaving_the_base_path_is_rejected(string $target): void
    {
        $transport = new RecordingTransport();
        $client = self::vectorClient($transport, 'https://api.example.com/prod');

        $this->expectException(UntrustedOriginException::class);

        try {
            $client->sendRequest(new Request('GET', $target));
        } finally {
            self::assertSame(0, $transport->callCount());
        }
    }

    private static function vectorClient(
        RecordingTransport $transport,
        string $origin = self::ORIGIN,
    ): SigV4SigningClient {
        return new SigV4SigningClient(
            $origin,
            'us-east-1',
            'service',
            FixedCredentialProvider::example(),
            new \DateTimeImmutable(self::VECTOR_DATE),
            $transport->asTransport(),
        );
    }
}
