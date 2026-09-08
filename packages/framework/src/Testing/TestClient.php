<?php

declare(strict_types=1);

namespace Kinetis\Testing;

use InvalidArgumentException;
use Kinetis\Http\Kernel;
use Kinetis\Http\MediaType;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Builds a PSR-7 request and dispatches it straight through a Kernel —
 * the same thing every Kernel test in this repo already does by hand
 * (construct a ServerRequest, call handle()), wrapped so a consumer
 * testing their own app doesn't have to.
 *
 * Every method returns a {@see TestResponse}, which is itself a PSR-7
 * response with assertions attached.
 *
 * Five request-building modes, each honest about the bytes it actually
 * sends — no mode claims a Content-Type the body doesn't match:
 *
 * - `get()`/`post()`/`put()`/`patch()` — the JSON shorthand. An array
 *   `$body` is always `json_encode()`'d; `getParsedBody()` is left null,
 *   matching how a real JSON request arrives (Kinetis's own dispatcher
 *   decodes a JSON body itself, at bind time, not via `getParsedBody()`).
 * - `postForm()`/`putForm()`/`patchForm()` — a genuine
 *   `application/x-www-form-urlencoded` body: the raw bytes are exactly
 *   `http_build_query($form)`, and nothing else is attached. The Kernel's
 *   own `RequestBodyMiddleware` parses those bytes on the way in, so
 *   `getParsedBody()` carries whatever a real request carries, not
 *   `$form` itself (`http_build_query()` loses information no wire-format
 *   body can express: every scalar becomes a string, and a `null` value
 *   is omitted entirely). `CsrfMiddleware`'s own `_token`-in-a-form-body
 *   fallback, and anything else reading `getParsedBody()`, is therefore
 *   reachable with exactly the shape a real form post produces.
 * - `raw()` — a plain string body sent exactly as given, no encoding
 *   inferred from it at all; for a webhook payload, binary content, or
 *   any shape none of the other modes cover. Nothing here attaches a
 *   parsed body; what the Kernel's own `RequestBodyMiddleware` makes of
 *   the bytes follows from the `Content-Type` the caller supplied, the
 *   same as a real request.
 * - `send()` — the direct escape hatch: dispatches a fully hand-built
 *   `ServerRequestInterface` exactly as given. This is what a multipart
 *   or uploaded-file request needs — this class deliberately never
 *   guesses a multipart boundary from a plain array — and it's also
 *   what every other method here is, underneath, a convenience for.
 *
 * `delete()` takes no body at all, matching the method's own convention.
 *
 * Every Content-Type this class inspects or sets is found under any
 * letter-case of the header name (HTTP field names carry no case
 * meaning, per RFC 7230) and classified through {@see MediaType}, so
 * `Application/JSON; charset=UTF-8` is recognized exactly like
 * `application/json`. A caller supplying the header under more than one
 * letter-case with two different values gets a clear exception rather
 * than one silently winning; two differently-cased keys agreeing on the
 * same value are collapsed to exactly one canonical entry, never left
 * as two — Nyholm's own header storage treats two differently-cased
 * keys as one *repeated* header and combines their values into a single
 * comma-joined field (`"application/json, application/json"`),
 * corrupting even a same-value "harmless" duplicate if both were left
 * in place. This resolution runs regardless of whether the current call
 * has a body to validate a shape against, so a header-only conflict (or
 * duplicate) on `get()`/`delete()`, or `request()` with an empty array
 * `$body`, is caught the same way a body-carrying call's is.
 *
 * Query parameters (`get()`'s `$query`, or `request()`'s own) are merged
 * into the request URI's own query component via `UriInterface::withQuery()`
 * — never raw string concatenation, which would corrupt a URI that
 * already carries a `#fragment` by appending the new query *after* the
 * fragment instead of before it. `getQueryParams()` is parsed back out of
 * that same, now-authoritative query string — the two always agree, the
 * same relationship a real incoming request has.
 *
 * A `Cookie` header stands in the same relationship to
 * `getCookieParams()`, and every mode that builds a request fills the
 * latter from the former, so cookies arrive in both places — the shape
 * every runtime adapter delivers, and the only one a consumer reading
 * cookies (kinetis/session's `SessionMiddleware`) looks at. `send()`
 * dispatches its request exactly as handed over, this included: a
 * hand-built request carries whatever cookies its builder gave it.
 */
final readonly class TestClient
{
    public function __construct(
        private Kernel $kernel,
    ) {}

    /**
     * @param array<string, mixed> $query
     * @param array<string, string> $headers
     */
    public function get(string $uri, array $query = [], array $headers = []): TestResponse
    {
        return $this->request('GET', $uri, query: $query, headers: $headers);
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, string> $headers
     */
    public function post(string $uri, array $body = [], array $headers = []): TestResponse
    {
        return $this->request('POST', $uri, body: $body, headers: $headers);
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, string> $headers
     */
    public function put(string $uri, array $body = [], array $headers = []): TestResponse
    {
        return $this->request('PUT', $uri, body: $body, headers: $headers);
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, string> $headers
     */
    public function patch(string $uri, array $body = [], array $headers = []): TestResponse
    {
        return $this->request('PATCH', $uri, body: $body, headers: $headers);
    }

    /**
     * @param array<string, string> $headers
     */
    public function delete(string $uri, array $headers = []): TestResponse
    {
        return $this->request('DELETE', $uri, headers: $headers);
    }

    /**
     * The JSON shorthand every method above delegates to. An array
     * `$body` is always sent as JSON — `Content-Type` defaults to
     * `application/json`, and an explicit override must itself be a
     * JSON media type as {@see MediaType::isJson()} classifies one
     * (`application/json`, or an `application/*+json` structured suffix
     * for a real API's own vendor media type — RFC 6839; parameters like
     * `; charset=UTF-8` are fine) — anything else throws rather than
     * silently sending JSON bytes under a Content-Type that claims
     * otherwise. postForm()/raw()/send() are the explicit, honest way to
     * send a body this shorthand can't; see each one's own docblock.
     *
     * @param array<string, mixed> $body
     * @param array<string, string> $headers
     * @param array<string, mixed> $query
     */
    public function request(
        string $method,
        string $uri,
        array $body = [],
        array $headers = [],
        array $query = [],
    ): TestResponse {
        // Content-Type is resolved unconditionally, not only when $body
        // carries something — a conflicting or duplicated Content-Type
        // header on a bodyless call (get()/delete(), or request() with
        // an empty array $body) must be caught too, not silently pass
        // through because there was nothing to validate a shape against.
        $headers = $body !== []
            ? self::withValidatedContentType(
                $headers,
                default: 'application/json',
                isAllowed: MediaType::isJson(...),
                describeAllowed: 'JSON-shaped (application/json, or an "application/*+json" suffix, '
                    . 'parameters allowed)',
                methodHint: 'Use postForm()/putForm()/patchForm() for a form-encoded body, raw() for a plain '
                    . 'string body, or send() for a fully hand-built PSR-7 request.',
            )
            : self::canonicalizeContentType($headers)[0];

        return $this->send(self::buildRequest(
            $method,
            $uri,
            $headers,
            $body !== [] ? \json_encode($body, \JSON_THROW_ON_ERROR) : null,
            $query,
        ));
    }

    /**
     * A genuine `application/x-www-form-urlencoded` body: the raw bytes
     * sent are exactly `http_build_query($form)`, parsed on the way in by
     * the same middleware a real request meets — so `getParsedBody()`
     * carries what the wire carries, not `$form`'s own PHP types; see
     * this class's own docblock for why.
     *
     * @param array<string, mixed> $form
     * @param array<string, string> $headers
     */
    public function postForm(string $uri, array $form, array $headers = []): TestResponse
    {
        return $this->form('POST', $uri, $form, $headers);
    }

    /**
     * @param array<string, mixed> $form
     * @param array<string, string> $headers
     */
    public function putForm(string $uri, array $form, array $headers = []): TestResponse
    {
        return $this->form('PUT', $uri, $form, $headers);
    }

    /**
     * @param array<string, mixed> $form
     * @param array<string, string> $headers
     */
    public function patchForm(string $uri, array $form, array $headers = []): TestResponse
    {
        return $this->form('PATCH', $uri, $form, $headers);
    }

    /**
     * A raw string body, sent exactly as given — no JSON encoding, no
     * form encoding, nothing inferred from it. For a webhook payload,
     * binary content, or any shape none of this class's other methods
     * already cover. Nothing here attaches a parsed body; what the
     * Kernel's own `RequestBodyMiddleware` makes of the bytes follows
     * from the `Content-Type` given, the same as a real request.
     *
     * @param array<string, string> $headers
     */
    public function raw(string $method, string $uri, string $body, array $headers = []): TestResponse
    {
        return $this->send(self::buildRequest($method, $uri, $headers, $body));
    }

    /**
     * The direct escape hatch: dispatches a fully hand-built PSR-7
     * request exactly as given, through the same real Kernel every
     * other method here uses. This is what a multipart or
     * uploaded-file request needs — this class deliberately never
     * guesses a multipart boundary from a plain array — or any other
     * shape none of the methods above cover. Every method above is,
     * underneath, just a convenience for building one of these and
     * calling this.
     */
    public function send(ServerRequestInterface $request): TestResponse
    {
        return new TestResponse($this->kernel->handle($request));
    }

    /**
     * @param array<string, mixed> $form
     * @param array<string, string> $headers
     */
    private function form(string $method, string $uri, array $form, array $headers): TestResponse
    {
        $headers = self::withValidatedContentType(
            $headers,
            default: 'application/x-www-form-urlencoded',
            isAllowed: self::isFormUrlencodedMediaType(...),
            describeAllowed: 'application/x-www-form-urlencoded (parameters like charset are allowed)',
            methodHint: 'Use request() for a JSON body, raw() for a plain string body, or send() for a fully '
                . 'hand-built PSR-7 request.',
        );

        return $this->send(self::buildRequest($method, $uri, $headers, \http_build_query($form)));
    }

    /**
     * The one place every mode above resolves its own Content-Type:
     * case-insensitively finds and canonicalizes whatever the caller
     * already supplied, defaults to $default when nothing was supplied,
     * and validates the result's bare media type — never the full
     * header string, so a `; charset=...` parameter never causes a
     * legitimate override to be rejected.
     *
     * $isAllowed receives the bare media type, which {@see MediaType}'s
     * own predicates re-normalize harmlessly — {@see MediaType::of()} is
     * idempotent — so one of them can be passed straight in.
     *
     * @param array<string, string> $headers
     * @param callable(string): bool $isAllowed
     * @return array<string, string>
     */
    private static function withValidatedContentType(
        array $headers,
        string $default,
        callable $isAllowed,
        string $describeAllowed,
        string $methodHint,
    ): array {
        [$headers, $contentType] = self::canonicalizeContentType($headers);

        if ($contentType === null) {
            $headers['Content-Type'] = $default;

            return $headers;
        }

        if (!$isAllowed(MediaType::of($contentType))) {
            throw new InvalidArgumentException(
                "Content-Type \"{$contentType}\" is not {$describeAllowed}. {$methodHint}",
            );
        }

        return $headers;
    }

    /**
     * Collapses every case-insensitive "content-type" key in $headers
     * down to exactly one canonical "Content-Type" entry — throwing if
     * two differently-cased keys disagree on the value, never leaving
     * two present even when they agree. HTTP header field names carry
     * no case meaning (RFC 7230), and Nyholm's own header storage treats
     * two differently-cased keys as one *repeated* header, combining
     * their values into a single comma-joined field
     * ("application/json, application/json") — corrupting even a
     * same-value "harmless" duplicate if both were left in the array
     * handed to it, not just a genuinely conflicting one.
     *
     * @param array<string, string> $headers
     * @return array{0: array<string, string>, 1: ?string}
     */
    private static function canonicalizeContentType(array $headers): array
    {
        $found = null;

        foreach ($headers as $key => $value) {
            if (\strtolower($key) !== 'content-type') {
                continue;
            }

            if ($found !== null && $found[1] !== $value) {
                throw new InvalidArgumentException(
                    "Conflicting Content-Type headers given: \"{$found[0]}: {$found[1]}\" and \"{$key}: "
                    . "{$value}\". HTTP header names are case-insensitive, so these describe the same header "
                    . 'with two different values — pass exactly one.',
                );
            }

            $found = [$key, $value];
        }

        if ($found === null) {
            return [$headers, null];
        }

        [, $value] = $found;

        foreach (\array_keys($headers) as $key) {
            if (\strtolower($key) === 'content-type') {
                unset($headers[$key]);
            }
        }

        $headers['Content-Type'] = $value;

        return [$headers, $value];
    }

    private static function isFormUrlencodedMediaType(string $mediaType): bool
    {
        return $mediaType === MediaType::FORM_URLENCODED;
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed> $query
     */
    private static function buildRequest(
        string $method,
        string $uri,
        array $headers,
        ?string $body,
        array $query = [],
    ): ServerRequestInterface {
        $request = new ServerRequest(method: $method, uri: $uri, headers: $headers, body: $body);

        if ($query !== []) {
            // Merged through the URI's own withQuery(), never raw string
            // concatenation onto $uri — a URI carrying a #fragment would
            // otherwise have the appended "?query" land *after* the
            // fragment and become part of it, not a real query string.
            $existingQuery = $request->getUri()->getQuery();
            $additional = \http_build_query($query);
            $mergedQuery = $existingQuery !== '' ? "{$existingQuery}&{$additional}" : $additional;

            $request = $request->withUri($request->getUri()->withQuery($mergedQuery));
        }

        // getQueryParams() must agree with the URI's own query string —
        // the same relationship a real request has, where the query
        // string is the one thing both are read from — never set
        // independently of it.
        \parse_str($request->getUri()->getQuery(), $queryParams);

        $request = $request->withQueryParams($queryParams);

        // The same holds for cookieParams and the Cookie header: every
        // runtime adapter fills the one from the other, and a consumer
        // reads only cookieParams. Taken off the built request rather
        // than the caller's array, so the header is found under
        // whatever letter-case it was spelled in, the way PSR-7 does.
        return $request->withCookieParams(self::parseCookieHeader($request->getHeaderLine('Cookie')));
    }

    /**
     * The `name=value` pairs of a Cookie header, in the shape the
     * runtime conformance suite pins for every adapter: `;`-separated,
     * surrounding whitespace insignificant, the first `=` dividing name
     * from value. A pair carrying no `=`, or an empty name, names no
     * cookie and is skipped; no header at all yields no cookies.
     *
     * @return array<string, string>
     */
    private static function parseCookieHeader(string $header): array
    {
        $cookies = [];

        foreach (\explode(';', $header) as $pair) {
            [$name, $value] = \array_pad(\explode('=', \trim($pair), 2), 2, null);

            if ($name !== null && $name !== '' && $value !== null) {
                $cookies[$name] = $value;
            }
        }

        return $cookies;
    }
}
