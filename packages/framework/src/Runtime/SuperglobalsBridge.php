<?php

declare(strict_types=1);

namespace Kinetis\Runtime;

use Kinetis\Http\Exception\UntrustedForwardedHeaderException;
use Kinetis\Http\Form\Exception\FormLimitExceededException;
use Kinetis\Http\Form\Exception\UnparseableFormBodyException;
use Kinetis\Http\Form\FormBody;
use Kinetis\Http\Form\FormLimits;
use Kinetis\Http\Form\StagedRequestBody;
use Kinetis\Http\Middleware\Exception\BodyTooLargeException;
use Kinetis\Http\Responses\ErrorResponse;
use Kinetis\Http\TrustedProxies;
use Kinetis\Runtime\Exception\RuntimeUnavailableException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Stream;
use Nyholm\Psr7Server\ServerRequestCreator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The superglobals-to-PSR-7 conversion and response emission FpmAdapter
 * and FrankenPhpAdapter share — the only two adapters whose environment
 * is a real PHP SAPI, and therefore the only two whose request arrives as
 * `$_SERVER`/`$_COOKIE`/`$_GET` plus a `php://input` stream.
 *
 * **This class parses the request body; PHP does not.** That is what
 * `enable_post_data_reading=0` is for, and why {@see assertCapabilities()}
 * refuses to run without it. Left on, PHP reads the body itself before
 * any Kinetis code exists: it populates `$_POST`/`$_FILES` for a POST
 * form, empties `php://input` doing so, silently drops everything past
 * `max_input_vars`, and answers a body over `post_max_size` with an empty
 * `$_POST` and no error at all — three different ways to hand a handler a
 * form that looks complete and is not. None of them is observable
 * afterwards: a form truncated to its first 1000 fields is
 * indistinguishable from a form that had 1000 fields.
 *
 * With the setting off, `php://input` carries the whole body for every
 * method including POST — which the runtime conformance suite asserts
 * against `php -S`, FrankenPHP and nginx + PHP-FPM alike — so the body
 * is staged under {@see FormLimits}' byte ceiling, counted in its raw
 * form, and parsed by `Kinetis\Http\Form`: the same code, the same
 * ceilings and the same refusals `kinetis/bref-adapter` and
 * `kinetis/roadrunner-adapter` apply to the same bytes.
 * `request_parse_body()` is not called, and cannot be: it reads the same
 * input stream, so it returns an empty form to anyone who staged the
 * body first, which makes "count the raw bytes, then let PHP parse them"
 * impossible rather than merely awkward.
 *
 * Two answers to a bad body, and only two. One that cannot be parsed is a
 * `400` carrying the fixed
 * {@see RuntimeAdapterInterface::MALFORMED_BODY_MESSAGE}, with a fixed
 * category logged and nothing from the request. One too large or too
 * complicated is a `413` naming the ceiling it met. Anything else — a
 * temporary stream that would not open, a bug — propagates, because it is
 * this worker's failure and not a client's.
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
    public static function handle(callable $handler, FormLimits $limits, TrustedProxies $trustedProxies): void
    {
        try {
            $request = self::requestFromGlobals($limits, $trustedProxies);
        } catch (UnparseableFormBodyException $e) {
            // The category, never the message: see that class for why a
            // parser's own text can never reach a log line.
            error_log('Malformed request body: ' . $e->category);
            self::emit(ErrorResponse::create(400, RuntimeAdapterInterface::MALFORMED_BODY_MESSAGE));

            return;
        } catch (UntrustedForwardedHeaderException) {
            error_log('Malformed request body: unreadable-forwarded-header');
            self::emit(ErrorResponse::create(400, RuntimeAdapterInterface::MALFORMED_BODY_MESSAGE));

            return;
        } catch (BodyTooLargeException|FormLimitExceededException $e) {
            // Safe to return as written: a limit message names a
            // configured ceiling and never anything from the request.
            self::emit(ErrorResponse::create(413, $e->getMessage()));

            return;
        }

        self::emit($handler($request));
    }

    public static function requestFromGlobals(FormLimits $limits, TrustedProxies $trustedProxies): ServerRequestInterface
    {
        self::assertCapabilities();

        $factory = new Psr17Factory();
        $creator = new ServerRequestCreator($factory, $factory, $factory, $factory);

        // Built without a body: fromGlobals() would open php://input
        // itself, and this class stages that stream under a byte ceiling
        // rather than handing it on unread.
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

        $declaredBytes = self::declaredContentLength($request);
        $input = fopen('php://input', 'r');

        if ($input === false) {
            throw RuntimeUnavailableException::missingFunction(self::class, 'php://input');
        }

        $body = StagedRequestBody::stage(Stream::create($input), $limits, $declaredBytes);
        $request = $request->withBody($body);

        return self::withFormBody($request, $limits);
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
                . 'See the "Form bodies: one contract under every runtime" section of docs/runtime-adapters.md.',
            );
        }
    }

    /**
     * The form the client sent, parsed from the staged bytes by
     * {@see FormBody} — the same entry point, the same contract and the
     * same ceilings every other runtime uses, with core's own multipart
     * parser behind it.
     *
     * Every count and every rule that bounds the parse is applied to the
     * raw body first, because that is the only place the real numbers
     * exist: a thousand repetitions of one name are a thousand pairs on
     * the wire and one leaf afterwards, and a part carrying no name at
     * all costs a parser everything and appears nowhere in the result.
     */
    private static function withFormBody(ServerRequestInterface $request, FormLimits $limits): ServerRequestInterface
    {
        $body = (string) $request->getBody();
        $request->getBody()->rewind();

        return FormBody::apply($request, $body, self::declaredContentLength($request), $limits);
    }

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
            ($response->getEmitter())();

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

    /**
     * The declared length, when the client declared one this framework
     * can act on. A header that is absent, or carries anything but a
     * non-negative integer, yields null: an unusable declaration is the
     * same as no declaration, and the actual byte count is what bounds
     * the request either way.
     */
    private static function declaredContentLength(ServerRequestInterface $request): ?int
    {
        $declared = $request->getHeaderLine('Content-Length');

        return ctype_digit($declared) ? (int) $declared : null;
    }
}
