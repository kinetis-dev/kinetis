<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http\Middleware;

use Kinetis\Config\Config;
use Kinetis\Http\CallableRequestHandler;
use Kinetis\Http\Middleware\SecurityHeadersMiddleware;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class SecurityHeadersMiddlewareTest extends TestCase
{
    public function test_sends_the_default_headers_with_no_configuration(): void
    {
        $response = self::process([]);

        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
        self::assertSame('DENY', $response->getHeaderLine('X-Frame-Options'));
        self::assertSame('strict-origin-when-cross-origin', $response->getHeaderLine('Referrer-Policy'));
    }

    public function test_the_breaking_headers_are_not_sent_unless_configured(): void
    {
        $response = self::process([]);

        self::assertFalse($response->hasHeader('Content-Security-Policy'));
        self::assertFalse($response->hasHeader('Permissions-Policy'));
        self::assertFalse($response->hasHeader('Strict-Transport-Security'));
        self::assertFalse($response->hasHeader('Cross-Origin-Opener-Policy'));
        self::assertFalse($response->hasHeader('Cross-Origin-Resource-Policy'));
        self::assertFalse($response->hasHeader('Cross-Origin-Embedder-Policy'));
    }

    /**
     * Each of the three severs something the web allows by default —
     * opener access, cross-origin embedding, un-opted-in subresources —
     * so each is sent only on request, and verbatim.
     */
    public function test_the_cross_origin_policies_are_sent_when_configured(): void
    {
        $response = self::process([
            'SECURITY_COOP' => 'same-origin-allow-popups',
            'SECURITY_CORP' => 'same-origin',
            'SECURITY_COEP' => 'require-corp',
        ]);

        self::assertSame('same-origin-allow-popups', $response->getHeaderLine('Cross-Origin-Opener-Policy'));
        self::assertSame('same-origin', $response->getHeaderLine('Cross-Origin-Resource-Policy'));
        self::assertSame('require-corp', $response->getHeaderLine('Cross-Origin-Embedder-Policy'));
    }

    public function test_a_cross_origin_policy_set_to_off_is_omitted(): void
    {
        $response = self::process(['SECURITY_COOP' => 'OFF']);

        self::assertFalse($response->hasHeader('Cross-Origin-Opener-Policy'));
    }

    public function test_a_configured_policy_is_sent(): void
    {
        $response = self::process([
            'SECURITY_CSP' => "default-src 'self'",
            'SECURITY_PERMISSIONS_POLICY' => 'geolocation=()',
        ]);

        self::assertSame("default-src 'self'", $response->getHeaderLine('Content-Security-Policy'));
        self::assertSame('geolocation=()', $response->getHeaderLine('Permissions-Policy'));
    }

    public function test_a_default_header_can_be_turned_off(): void
    {
        $response = self::process([
            'SECURITY_FRAME_OPTIONS' => 'off',
            'SECURITY_REFERRER_POLICY' => 'off',
        ]);

        self::assertFalse($response->hasHeader('X-Frame-Options'));
        self::assertFalse($response->hasHeader('Referrer-Policy'));
        // Never configurable: nothing legitimate depends on sniffing.
        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
    }

    public function test_hsts_is_assembled_from_its_parts(): void
    {
        self::assertSame(
            'max-age=31536000; includeSubDomains',
            self::process(['SECURITY_HSTS_MAX_AGE' => '31536000'])->getHeaderLine('Strict-Transport-Security'),
        );

        self::assertSame(
            'max-age=600',
            self::process([
                'SECURITY_HSTS_MAX_AGE' => '600',
                'SECURITY_HSTS_INCLUDE_SUBDOMAINS' => 'false',
            ])->getHeaderLine('Strict-Transport-Security'),
        );

        self::assertSame(
            'max-age=600; includeSubDomains; preload',
            self::process([
                'SECURITY_HSTS_MAX_AGE' => '600',
                'SECURITY_HSTS_PRELOAD' => 'true',
            ])->getHeaderLine('Strict-Transport-Security'),
        );
    }

    public function test_a_zero_hsts_max_age_disables_the_header_rather_than_throwing(): void
    {
        // RFC 6797-meaningful: "disable HSTS for this origin," not an
        // error.
        self::assertFalse(self::process(['SECURITY_HSTS_MAX_AGE' => '0'])->hasHeader('Strict-Transport-Security'));
    }

    public function test_a_negative_hsts_max_age_throws_rather_than_being_silently_disabled(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('SECURITY_HSTS_MAX_AGE must not be negative');

        self::process(['SECURITY_HSTS_MAX_AGE' => '-1']);
    }

    /**
     * Sent over a plain-HTTP request too: a browser is required to
     * ignore HSTS from a non-secure transport, and a scheme check would
     * suppress it behind a TLS-terminating proxy, where it is wanted.
     */
    public function test_hsts_is_sent_regardless_of_the_request_scheme(): void
    {
        $response = self::process(
            ['SECURITY_HSTS_MAX_AGE' => '600'],
            new ServerRequest('GET', 'http://example.test/'),
        );

        self::assertSame('max-age=600; includeSubDomains', $response->getHeaderLine('Strict-Transport-Security'));
    }

    public function test_a_header_the_response_already_set_is_left_alone(): void
    {
        $response = self::process(
            ['SECURITY_CSP' => "default-src 'self'"],
            handler: static fn (): ResponseInterface => new Response(200, [
                'Content-Security-Policy' => "default-src 'self' https://cdn.example.test",
            ]),
        );

        self::assertSame(
            "default-src 'self' https://cdn.example.test",
            $response->getHeaderLine('Content-Security-Policy'),
        );
    }

    /**
     * A CR or LF in a configured value would both throw inside
     * withHeader() and be a header injection. Stripped at construction,
     * so process() cannot fail on it.
     */
    public function test_a_value_carrying_a_line_break_is_stripped_rather_than_thrown_on(): void
    {
        $response = self::process([
            'SECURITY_CSP' => "default-src 'self'\r\nX-Injected: yes",
        ]);

        self::assertSame("default-src 'self'X-Injected: yes", $response->getHeaderLine('Content-Security-Policy'));
        self::assertFalse($response->hasHeader('X-Injected'));
    }

    public function test_surrounding_whitespace_is_trimmed_from_a_configured_value(): void
    {
        $response = self::process(['SECURITY_CSP' => "  default-src 'self'  "]);

        self::assertSame("default-src 'self'", $response->getHeaderLine('Content-Security-Policy'));
    }

    /**
     * @param array<string, string> $config
     * @param ?callable(ServerRequestInterface): ResponseInterface $handler
     */
    private static function process(
        array $config,
        ?ServerRequestInterface $request = null,
        ?callable $handler = null,
    ): ResponseInterface {
        return new SecurityHeadersMiddleware(new Config($config))->process(
            $request ?? new ServerRequest('GET', 'https://example.test/'),
            new CallableRequestHandler($handler ?? static fn (): ResponseInterface => new Response(200)),
        );
    }
}
