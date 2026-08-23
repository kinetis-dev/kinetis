<?php

declare(strict_types=1);

namespace Kinetis\Http\Middleware;

use Kinetis\Config\Config;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Response security headers, registered unconditionally as the
 * outermost global middleware by Kernel — the same "costs nothing when
 * nothing's wrong, closes a real gap when something does" reasoning
 * MaxBodySizeMiddleware already uses.
 *
 * Outside ExceptionHandlerMiddleware rather than inside it, which is
 * the one deliberate exception to that class otherwise being outermost:
 * a 500 it generates is still a response a browser interprets, and
 * headers have to reach it too. Nothing here can throw at request time
 * — configuration is read once at construction and stripped of CR/LF
 * (a header value carrying either would both throw and be a header
 * injection), leaving process() unable to do anything but set headers.
 *
 * Two tiers, split by what breaks when the value is wrong:
 *
 * `X-Content-Type-Options`, `X-Frame-Options`, and `Referrer-Policy`
 * are sent by default. Nothing legitimate depends on content sniffing,
 * on being framed, or on leaking a full referrer cross-origin, so a
 * secure default costs an application nothing and protects one that
 * never thought about it.
 *
 * A Content-Security-Policy, a Permissions-Policy, HSTS, and the three
 * cross-origin policies are sent only when configured. Each of them
 * breaks a working application when it is wrong — a policy that omits a
 * real dependency blocks it, HSTS on the wrong host is not quickly
 * reversible, and the cross-origin three each sever something the web
 * allows by default:
 *
 * - `Cross-Origin-Opener-Policy` cuts the `window.opener` link, which is
 *   how an OAuth or payment popup reports back. `same-origin-allow-popups`
 *   keeps popups this application opens; `same-origin` also severs the
 *   link when one of its own pages *is* the popup, which is the identity
 *   provider case.
 * - `Cross-Origin-Resource-Policy: same-origin` stops other origins
 *   embedding this application's responses — including images and fonts
 *   they legitimately embed today. It does not affect a CORS request, so
 *   an API consumed through {@see CorsMiddleware} is unaffected.
 * - `Cross-Origin-Embedder-Policy: require-corp` demands that every
 *   cross-origin subresource opt in, and blocks each one that has not.
 *   It is the prerequisite for cross-origin isolation and the most
 *   disruptive of the three.
 *
 * A header already present on the response is never replaced, so a
 * single route can set its own policy and keep it.
 */
final class SecurityHeadersMiddleware implements MiddlewareInterface
{
    /**
     * Any of the configurable values set to this — in any case, since
     * the valid values of these headers are conventionally uppercase
     * and `OFF` would otherwise be sent verbatim as an invalid header,
     * silently disabling the protection while looking configured.
     */
    private const string DISABLED = 'off';

    /** @var array<string, string> */
    private readonly array $headers;

    public function __construct(Config $config)
    {
        $headers = ['X-Content-Type-Options' => 'nosniff'];

        $optional = [
            'X-Frame-Options' => $config->string('SECURITY_FRAME_OPTIONS', 'DENY'),
            'Referrer-Policy' => $config->string('SECURITY_REFERRER_POLICY', 'strict-origin-when-cross-origin'),
            'Content-Security-Policy' => $config->string('SECURITY_CSP', ''),
            'Permissions-Policy' => $config->string('SECURITY_PERMISSIONS_POLICY', ''),
            'Strict-Transport-Security' => self::hsts($config),
            'Cross-Origin-Opener-Policy' => $config->string('SECURITY_COOP', ''),
            'Cross-Origin-Resource-Policy' => $config->string('SECURITY_CORP', ''),
            'Cross-Origin-Embedder-Policy' => $config->string('SECURITY_COEP', ''),
        ];

        foreach ($optional as $name => $value) {
            $clean = self::sanitize($value);

            if ($clean !== '' && \strcasecmp($clean, self::DISABLED) !== 0) {
                $headers[$name] = $clean;
            }
        }

        $this->headers = $headers;
    }

    #[\Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);

        foreach ($this->headers as $name => $value) {
            if (!$response->hasHeader($name)) {
                $response = $response->withHeader($name, $value);
            }
        }

        return $response;
    }

    /**
     * Sent whenever a max-age is configured, without checking the
     * request's own scheme: a browser is required to ignore HSTS
     * received over a non-secure transport (RFC 6797), so there is
     * nothing to guard against — and a scheme check would misfire
     * anyway behind a proxy that terminates TLS.
     */
    private static function hsts(Config $config): string
    {
        $maxAge = $config->int('SECURITY_HSTS_MAX_AGE', 0);

        if ($maxAge <= 0) {
            return '';
        }

        $value = 'max-age=' . $maxAge;

        if ($config->bool('SECURITY_HSTS_INCLUDE_SUBDOMAINS', true)) {
            $value .= '; includeSubDomains';
        }

        if ($config->bool('SECURITY_HSTS_PRELOAD', false)) {
            $value .= '; preload';
        }

        return $value;
    }

    private static function sanitize(string $value): string
    {
        return \trim(\str_replace(["\r", "\n"], '', $value));
    }
}
