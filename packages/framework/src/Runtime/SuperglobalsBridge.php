<?php

declare(strict_types=1);

namespace Kinetis\Runtime;

use Kinetis\Http\Exception\UntrustedForwardedHeaderException;
use Kinetis\Http\Responses\ErrorResponse;
use Kinetis\Http\TrustedProxies;
use Kinetis\Runtime\Exception\RuntimeUnavailableException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Stream;
use Nyholm\Psr7Server\ServerRequestCreator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

/**
 * The superglobals-to-PSR-7 conversion and response emission FpmAdapter
 * and FrankenPhpAdapter share — the only two adapters whose environment
 * is a real PHP SAPI, and therefore the only two whose request arrives as
 * `$_SERVER`/`$_COOKIE`/`$_GET` plus a `php://input` stream.
 *
 * **This class does not parse the request body, and PHP must not
 * either.** That is what `enable_post_data_reading=0` is for, and why
 * {@see assertCapabilities()} refuses to run without it. Left on, PHP
 * reads the body itself before any Kinetis code exists: it populates
 * `$_POST`/`$_FILES` for a POST form, empties `php://input` doing so,
 * silently drops everything past `max_input_vars`, and answers a body
 * over `post_max_size` with an empty `$_POST` and no error at all —
 * three different ways to hand a handler a form that looks complete and
 * is not. None of them is observable afterwards: a form truncated to its
 * first 1000 fields is indistinguishable from a form that had 1000
 * fields.
 *
 * With the setting off, `php://input` carries the whole body for every
 * method including POST — which the runtime conformance suite asserts
 * against `php -S`, FrankenPHP and nginx + PHP-FPM alike — so this hands
 * that stream on unread, as the body of a raw PSR-7 request, and
 * {@see \Kinetis\Http\Middleware\RequestBodyMiddleware} stages, bounds
 * and parses it inside the Kernel. `request_parse_body()` is not called,
 * and cannot be: it reads the same input stream, so it would leave
 * nothing for the middleware that actually owns the body.
 *
 * One failure belongs to this class rather than to that middleware: a
 * forwarded header from an untrusted source, which decides the request's
 * own scheme and client address and so has to be settled while the
 * request is still being built. It is answered with the same fixed `400`
 * a malformed body gets. Anything else raised while the request is being
 * built or handled — a bug, an environment that cannot be read —
 * propagates, because it is this worker's failure and not a client's.
 *
 * The one exception is a streamed body's emitter, which runs after the
 * status and headers are already on the wire: see {@see emit()} for why
 * that failure is contained here and nowhere else.
 */
final class SuperglobalsBridge
{
    /**
     * The full request/handle/emit cycle for one request, including the
     * failures that happen *before* $handler — and so before
     * Kernel/ExceptionHandlerMiddleware — ever runs. Both adapters call
     * this rather than requestFromGlobals()+emit() directly, so the
     * policy lives in one place instead of being duplicated in each.
     *
     * @param callable(ServerRequestInterface): ResponseInterface $handler
     */
    public static function handle(callable $handler, TrustedProxies $trustedProxies): void
    {
        try {
            $request = self::requestFromGlobals($trustedProxies);
        } catch (UntrustedForwardedHeaderException) {
            error_log('Rejected request: unreadable-forwarded-header');
            self::emit(ErrorResponse::create(400, RuntimeAdapterInterface::MALFORMED_BODY_MESSAGE));

            return;
        }

        self::emit($handler($request));
    }

    public static function requestFromGlobals(TrustedProxies $trustedProxies): ServerRequestInterface
    {
        self::assertCapabilities();

        $factory = new Psr17Factory();
        $creator = new ServerRequestCreator($factory, $factory, $factory, $factory);

        // Built without a body, then given one: fromGlobals() would read
        // php://input itself, and the body belongs to the Kernel's own
        // RequestBodyMiddleware, which is what bounds it.
        $request = $creator->fromArrays(
            $_SERVER,
            ServerRequestCreator::getHeadersFromServer($_SERVER),
            $_COOKIE,
            $_GET,
            null,
            [],
            Stream::create(''),
        );

        $request = self::withClientIdentity($request, $trustedProxies);

        $input = fopen('php://input', 'r');

        if ($input === false) {
            throw RuntimeUnavailableException::missingFunction(self::class, 'php://input');
        }

        return $request->withBody(Stream::create($input));
    }

    /**
     * What this environment has to have been configured to do before any
     * of the above means anything.
     *
     * `enable_post_data_reading` is the one setting that decides whether
     * PHP or Kinetis reads the request body, and it is readable directly,
     * so there is nothing to infer. Left at its default, `php://input` is
     * empty for exactly the requests this class most needs it for — a
     * POST form — and the body Kinetis would go on to parse is not the
     * client's at all. Refused rather than assumed, for the same reason
     * `kinetis/roadrunner-adapter` refuses a worker that cannot tell it
     * whether RoadRunner already parsed the body: a capability that
     * cannot be confirmed is not a capability.
     */
    private static function assertCapabilities(): void
    {
        if ((bool) ini_get('enable_post_data_reading')) {
            throw RuntimeUnavailableException::misconfiguredSapi(
                'enable_post_data_reading must be 0 so Kinetis reads and bounds the request body itself. '
                . 'Left on, PHP parses form bodies before any Kinetis code runs, silently truncating them at '
                . 'its own max_input_vars/post_max_size limits and leaving php://input empty. '
                . 'See the "Request bodies: one contract under every runtime" section of docs/runtime-adapters.md.',
            );
        }
    }

    /**
     * The SAPI emission boundary both adapters share, and the last point
     * either of them controls.
     *
     * A streamed body's emitter is the only thing here that runs after
     * the response has begun leaving the process, so it is the only
     * throwable this class contains rather than propagates. By the time
     * it fails the status, the headers and some number of body bytes are
     * on the wire: there is no replacement response to send, and under
     * FrankenPHP an escaping throwable leaves the request callback and
     * terminates the worker, discarding the warm state every subsequent
     * request on that thread would have used — one client's broken
     * stream taken out on every client after it. Contained, reported to
     * the SAPI error log, and the loop goes on to the next request; the
     * client sees a truncated body, which is the only thing a half-sent
     * response can look like.
     *
     * Only the emitter call is inside the `try`. Status and headers are
     * sent before it, request construction and the handler before that,
     * and every failure there is still this worker's to propagate.
     *
     * The containment lives here rather than in the Kernel: the emitter
     * a `StreamableResponseInterface` from `Kernel::handle()` carries
     * releases the request scope and then re-raises what failed, which is
     * what any direct caller of it is entitled to see.
     */
    public static function emit(ResponseInterface $response): void
    {
        http_response_code($response->getStatusCode());

        foreach ($response->getHeaders() as $name => $values) {
            foreach ($values as $index => $value) {
                header("{$name}: {$value}", replace: $index === 0);
            }
        }

        // A StreamableResponseInterface's body is never read via getBody()
        // — its emitter writes+flushes output directly and incrementally,
        // since FrankenPHP/FPM both run this in a real per-request SAPI
        // context where flush() reaches the client immediately rather than
        // being buffered until the script ends.
        if ($response instanceof StreamableResponseInterface) {
            try {
                ($response->getEmitter())();
            } catch (Throwable $e) {
                error_log(sprintf(
                    'Streamed response emitter failed after the status and headers were sent; the client keeps whatever body was written: %s: %s in %s:%d',
                    $e::class,
                    $e->getMessage(),
                    $e->getFile(),
                    $e->getLine(),
                ));
            }

            return;
        }

        echo (string) $response->getBody();
    }

    /**
     * One authority and one client address, both taken from the one place
     * that knows them.
     *
     * The authority is the `Host` header the client sent. Without this the
     * request carries two answers to "where was I addressed?" that need
     * not agree — the URI takes its host from `HTTP_HOST` but its port
     * from `SERVER_PORT`, so a request to `example.com` served on port
     * 8080 builds `example.com:8080`, and the `Host` header itself arrives
     * twice, once as the client sent it and once as the URI implies it.
     * Either is enough to make a generated absolute URL point somewhere
     * the client cannot follow.
     *
     * The scheme and the client address come from the forwarded headers
     * only when {@see TrustedProxies} says the peer that connected is an
     * edge. PSR-7's own server-request creation applies `X-Forwarded-Proto`
     * unconditionally, which is why the URI is rebuilt here afterwards
     * rather than left as it arrived: on a directly reachable listener
     * that header is the client's to set, and a client that can choose
     * the scheme its own request appears to have arrived over can choose
     * whether a `Secure` cookie is set and where an OAuth redirect points.
     *
     * Read from `$_SERVER` rather than through `getHeaderLine()` for the
     * authority, which would join those two values into one string that is
     * neither. The port pattern is the one PSR-7's own host parsing uses,
     * so an IPv6 literal authority splits the same way here as it does
     * there; a value that doesn't match is left as the client sent it,
     * host and all, since the server in front has already decided which
     * authorities it answers for.
     */
    private static function withClientIdentity(ServerRequestInterface $request, TrustedProxies $trustedProxies): ServerRequestInterface
    {
        $uri = $request->getUri();
        $serverParams = $request->getServerParams();
        $remoteAddr = is_string($serverParams['REMOTE_ADDR'] ?? null) ? $serverParams['REMOTE_ADDR'] : null;

        // Whatever the PSR-7 creator made of X-Forwarded-Proto is
        // discarded first: the scheme this environment actually serves is
        // the starting point, and only a trusted edge moves it.
        $uri = $uri->withScheme(($serverParams['HTTPS'] ?? '') !== '' && ($serverParams['HTTPS'] ?? '') !== 'off' ? 'https' : 'http');

        $forwardedScheme = $trustedProxies->forwardedScheme($remoteAddr, $request->getHeaderLine('X-Forwarded-Proto'));

        if ($forwardedScheme !== null) {
            $uri = $uri->withScheme($forwardedScheme);
        }

        $declared = $_SERVER['HTTP_HOST'] ?? null;

        if (is_string($declared) && $declared !== '') {
            $host = $declared;
            $port = null;

            if (preg_match('/^(.+):(\d+)$/', $declared, $matches) === 1) {
                $host = $matches[1];
                $port = (int) $matches[2];
            }

            // withPort() drops a port that is the default for the scheme,
            // so the scheme has to be settled first — it is, just above.
            $uri = $uri->withHost($host)->withPort($port);
            $request = $request->withHeader('Host', $declared);
        }

        return $request->withUri($uri, preserveHost: true);
    }
}
