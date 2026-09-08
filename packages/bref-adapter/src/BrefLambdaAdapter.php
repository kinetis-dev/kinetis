<?php

declare(strict_types=1);

namespace Kinetis\BrefAdapter;

use JsonException;
use Kinetis\BrefAdapter\Exception\BrefAdapterException;
use Kinetis\BrefAdapter\Exception\MalformedRequestBodyException;
use Kinetis\Http\Responses\ErrorResponse;
use Kinetis\Runtime\Exception\RuntimeUnavailableException;
use Kinetis\Runtime\RuntimeAdapterInterface;
use Kinetis\Runtime\StreamableResponseInterface;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use stdClass;
use Throwable;

/**
 * Bridges AWS Lambda's Runtime API to the Kernel, so the same application
 * code that runs under FrankenPHP, FPM, or RoadRunner also runs behind
 * API Gateway without changes. Polls .../runtime/invocation/next for the
 * next event, converts it to PSR-7, and posts the Kernel's response back
 * as the function's return payload. Supports the API Gateway HTTP API
 * (payload format 2.0) event shape used by Bref-style Lambda functions;
 * ALB/REST API (1.0) payloads aren't handled yet — see
 * {doc}`runtime-adapters` for the complete supported/unsupported feature
 * list, including cookies, REMOTE_ADDR, and binary body handling.
 *
 * Talks to the Runtime API with plain stream-context HTTP rather than
 * ext-curl or the bref/bref package: it's a synchronous request/response
 * loop with no need for anything heavier, and every extra dependency here
 * is more surface for a framework that's supposed to stay runtime-agnostic
 * at its core.
 *
 * Lives in its own package rather than in core because the Lambda
 * deployment target is one most consumers never use, and core carries
 * only the two adapters that must always work.
 *
 * This adapter decides what a Lambda event *is* — identity, headers,
 * cookies, the base64 envelope — and nothing about what its body
 * contains. The body is handed on as raw PSR-7 bytes, and the Kernel's
 * own `RequestBodyMiddleware` bounds and parses it under
 * `Kinetis\Http\Form`'s ceilings, the same ones the SAPI bridge and
 * kinetis/roadrunner-adapter deliver their bodies to. The same form sent
 * to any of the three is read into the same PSR-7 structures or refused
 * by all three with the same status.
 *
 * Those ceilings apply after delivery, not before it: by the time this
 * adapter sees an event, API Gateway has already accepted the request
 * and materialized its whole body in memory, up to the platform's own
 * 6 MB invocation payload limit. Kinetis refuses an oversized body; it
 * cannot stop AWS from having received one.
 */
final class BrefLambdaAdapter implements RuntimeAdapterInterface
{
    private const RUNTIME_HEADER_PREFIX = 'lambda-runtime-aws-request-id:';

    /** assertOptionalScalar()'s own $description for an is_string predicate — reused across every optional string field it validates. */
    private const string DESCRIPTION_STRING = 'a string';

    /**
     * AWS's Runtime API reference is explicit: "Do not set a timeout on
     * the GET request as the response may be delayed" — the long poll
     * only answers once an invocation exists or the execution
     * environment is about to be reclaimed, which can be arbitrarily far
     * in the future while the environment sits idle. A finite stand-in,
     * however large, is still a timeout AWS's own contract says not to
     * set — -1.0 is PHP's real, documented "no timeout at all" sentinel
     * for the http stream wrapper's `timeout` context option (php.net's
     * filesystem/streams configuration reference: a negative
     * `default_socket_timeout` value means an infinite timeout, and this
     * context option is that same read timeout, explicitly set here
     * rather than left to inherit the ini default). Verified directly,
     * not assumed from the docs alone: with `default_socket_timeout`
     * forced down to 1 second via `-d` and a real server delaying its
     * response by 2.5 seconds, a request using `timeout: -1.0` still
     * waited the full 2.5 seconds and succeeded, on both PHP 8.4 and
     * PHP 8.5 — proving this genuinely disables the timeout rather than
     * silently falling back to the (here, deliberately shrunk) ini
     * default.
     */
    private const float DEFAULT_NEXT_INVOCATION_TIMEOUT_SECONDS = -1.0;

    /**
     * The response/error POSTs are a loopback call to the Runtime API
     * sidecar within the same execution environment — nothing like the
     * next-invocation long poll, which can legitimately sit idle for a
     * long time. A short, finite, explicit timeout here is what turns a
     * genuinely stuck sidecar into a fast, diagnosable failure instead of
     * silently inheriting the same effectively-unbounded wait as the
     * poll above.
     */
    private const float DEFAULT_RESPONSE_TIMEOUT_SECONDS = 10.0;

    public function __construct(
        private readonly string $runtimeApi,
        private readonly float $nextInvocationTimeoutSeconds = self::DEFAULT_NEXT_INVOCATION_TIMEOUT_SECONDS,
        private readonly float $responseTimeoutSeconds = self::DEFAULT_RESPONSE_TIMEOUT_SECONDS,
    ) {}

    /**
     * @param callable(ServerRequestInterface): ResponseInterface $handler
     */
    #[\Override]
    public function run(callable $handler): void
    {
        // Intentionally infinite: this loop *is* the Lambda execution
        // model — the runtime freezes/kills the process between
        // invocations rather than this method ever returning normally.
        // @phpstan-ignore-next-line while.alwaysTrue
        while (true) {
            [$requestId, $rawBody] = $this->nextInvocation();

            try {
                // Decoding lives inside the try, not in nextInvocation()
                // itself: $requestId is already known at this point (it
                // comes from a response header, never the body), so a
                // malformed event is a per-invocation failure the Runtime
                // API can be told about via postError() below, not an
                // uncaught exception that kills the whole worker loop —
                // and not silently downgraded into an empty, plausible-
                // looking GET / that reaches application routing either.
                $event = self::decodeInvocationEvent($rawBody);
                $this->postResponse($requestId, self::handleEvent($event, $handler));
            } catch (Throwable $e) {
                $this->postError($requestId, $e);
            }
        }
    }

    #[\Override]
    public function isPersistent(): bool
    {
        return true;
    }

    /**
     * One invocation, from a decoded event to the payload to post back:
     * the Lambda counterpart of SuperglobalsBridge::handle(), and like it
     * the one place a failure that happens *before* $handler is turned
     * into a response. A body this adapter cannot decode — a
     * `isBase64Encoded` payload that is not valid base64 — is the
     * client's mistake, answered with the same 400 every other adapter
     * gives, not an invocation error (which API Gateway renders as a
     * 502, with the real message in CloudWatch only). Anything else
     * escapes to run()'s postError().
     *
     * What a body *contains* is not judged here at all: the request goes
     * on raw, and the Kernel's own RequestBodyMiddleware bounds and
     * parses it under the application's own FormLimits — the same
     * ceilings, the same `413` and the same `400` every other runtime
     * applies to the same bytes.
     *
     * Public so the runtime conformance suite can drive exactly the code
     * run() drives, without a Runtime API in the loop.
     *
     * @param array<string,mixed> $event
     * @param callable(ServerRequestInterface): ResponseInterface $handler
     * @return array{statusCode:int,headers:array<string,string>,cookies:list<string>,body:string,isBase64Encoded:bool}
     */
    public static function handleEvent(array $event, callable $handler): array
    {
        try {
            $request = self::requestFromEvent($event);
        } catch (MalformedRequestBodyException) {
            // A fixed classification, never a message — see
            // UnparseableFormBodyException for why a decoder's own text
            // can never reach a log line.
            error_log('Malformed request body: invalid-base64');

            return self::responseToPayload(ErrorResponse::create(400, RuntimeAdapterInterface::MALFORMED_BODY_MESSAGE));
        }

        return self::responseToPayload($handler($request));
    }

    /**
     * The event as a PSR-7 request. Identity — scheme, host, port, path,
     * raw query, protocol version — is settled first and in one place
     * (see {@see LambdaRequestIdentity}), so the URI, the `Host` header
     * and the request target cannot disagree; a contradictory or
     * malformed event is refused here rather than dispatched as a
     * plausible request built from whichever fields happened to be
     * usable.
     *
     * @param array<string,mixed> $event
     */
    public static function requestFromEvent(array $event): ServerRequestInterface
    {
        $headers = self::headersFromEvent($event);
        $identity = LambdaRequestIdentity::fromEvent($event, $headers);

        // requestContext.http.sourceIp is API Gateway's own record of the
        // real client address — the closest thing to what REMOTE_ADDR
        // would be under a real TCP connection, which nothing here has:
        // every invocation arrives over the Runtime API, not a socket PHP
        // itself accepted. Without this, every request looks identical to
        // RateLimitMiddleware's identifierFor() (see docs/middleware.md),
        // collapsing every distinct Lambda client into one shared bucket.
        $sourceIp = $event['requestContext']['http']['sourceIp'] ?? null;
        $serverParams = is_string($sourceIp) && $sourceIp !== '' ? ['REMOTE_ADDR' => $sourceIp] : [];

        $factory = new Psr17Factory();
        $request = $factory->createServerRequest($identity->method, $identity->uri(), $serverParams)
            ->withProtocolVersion($identity->protocolVersion)
            ->withRequestTarget($identity->requestTarget());

        foreach ($headers as $name => $value) {
            // (string) again, not redundantly: the lowercased name was
            // cast on the way into $headers, and PHP coerced it straight
            // back to an int on the way in as an array key. Only a real
            // string argument survives to withHeader(), which rejects an
            // int outright even though "123" is a valid header name.
            $request = $request->withHeader((string) $name, $value);
        }

        // One authority, the identity's — a host header the event
        // carried has already been checked against it, and one it did
        // not carry has to exist all the same for anything downstream
        // that reads it.
        $request = $request->withHeader('Host', $identity->authority());

        // The raw query is the authority for the parameters too, so this
        // adapter reads the same bytes with the same function every other
        // runtime does. queryStringParameters is not consulted anywhere;
        // see LambdaRequestIdentity for why.
        parse_str($identity->rawQueryString, $queryParams);
        $request = $request->withQueryParams($queryParams);

        $request = self::applyCookies($request, $event);

        return $request->withBody($factory->createStream(self::decodeBody($event)));
    }

    /**
     * The event's headers, lowercased into the map the rest of this
     * adapter reads — and refused outright when two spellings of one
     * name arrive.
     *
     * A canonical payload-v2 event carries each header once, already
     * comma-folded by API Gateway. A direct invocation carries whatever
     * its caller wrote, and a JSON object may legitimately hold both
     * `Host` and `host`. Lowercasing them into one map makes the second
     * silently win, so an event can name two authorities, two forwarded
     * schemes or two content lengths while {@see LambdaRequestIdentity}
     * validates only the survivor — an ambiguity resolved by key order
     * is not an identity anything downstream can rely on.
     *
     * Names and values are checked here too, not only counted: a name
     * that is not an RFC 9110 token, or a value carrying a control
     * character, is a header PSR-7 would reject or a client could break a
     * log line with. This runs on the public {@see requestFromEvent()}
     * boundary as well as the run loop's own decoded JSON, so an array
     * handed straight to this class is held to the same shape the wire
     * is.
     *
     * @param array<string,mixed> $event
     * @return array<string,string>
     */
    private static function headersFromEvent(array $event): array
    {
        $raw = $event['headers'] ?? null;

        if ($raw === null) {
            return [];
        }

        if (!is_array($raw)) {
            throw BrefAdapterException::malformedInvocationEvent('headers must be an object with string values when present.');
        }

        $headers = [];
        $spellings = [];

        foreach ($raw as $name => $value) {
            // (string) $name, not the raw array key: a canonical JSON
            // object key like "123" is a real, RFC 9110-valid header name
            // (a token, which includes digits) — but json_decode(...,
            // associative: true) coerces a purely-numeric string array
            // key to a genuine PHP int, and PSR-7's own withHeader()
            // requires a string, throwing InvalidArgumentException on an
            // int even though nothing about the header itself is
            // invalid.
            $name = (string) $name;

            if (!is_string($value)) {
                throw BrefAdapterException::malformedInvocationEvent('headers must be an object with string values when present.');
            }

            if (preg_match('/^[!#$%&\'*+.^_`|~0-9A-Za-z-]+$/', $name) !== 1) {
                throw BrefAdapterException::malformedInvocationEvent('a header name is not an RFC 9110 token.');
            }

            if (preg_match('/[\x00-\x08\x0A-\x1F\x7F]/', $value) === 1) {
                throw BrefAdapterException::malformedInvocationEvent('a header value contains a control character.');
            }

            $lowercased = strtolower($name);

            if (isset($spellings[$lowercased])) {
                throw BrefAdapterException::malformedInvocationEvent('the event carries one header name under two spellings.');
            }

            $spellings[$lowercased] = true;
            // Lowercased on the way in so identity resolution can look up
            // `host`/`x-forwarded-proto` without repeating a
            // case-insensitive search; PSR-7 header lookup is
            // case-insensitive either way.
            $headers[$lowercased] = $value;
        }

        return $headers;
    }

    /**
     * Payload format 2.0 never puts cookies in $headers at all — they
     * arrive as their own top-level list, one "name=value" pair per
     * entry, specifically so API Gateway never has to fold multiple
     * cookies into one header the way it does for ordinary multi-value
     * headers. Reconstructing the Cookie header here (and parsing it
     * into cookieParams) is what makes cookie/session authentication
     * reachable at all under this adapter — see kinetis/session's
     * SessionMiddleware, which reads cookieParams first.
     *
     * @param array<string,mixed> $event
     */
    private static function applyCookies(ServerRequestInterface $request, array $event): ServerRequestInterface
    {
        $raw = $event['cookies'] ?? null;

        if ($raw === null) {
            return $request;
        }

        // Not filtered — validated. array_filter('is_string') on this
        // public boundary would drop a malformed entry and hand on the
        // rest as though the client had sent only those, which is the
        // silently-shortened shape this framework refuses everywhere
        // else.
        if (!is_array($raw) || !array_is_list($raw) || array_any($raw, static fn (mixed $entry): bool => !is_string($entry))) {
            throw BrefAdapterException::malformedInvocationEvent('cookies must be a list of strings when present.');
        }

        /** @var list<string> $cookies */
        $cookies = $raw;

        if ($cookies === []) {
            return $request;
        }

        $cookieHeader = implode('; ', $cookies);

        return $request->withHeader('Cookie', $cookieHeader)->withCookieParams(self::parseCookieHeader($cookieHeader));
    }

    /**
     * @param array<string,mixed> $event
     */
    private static function decodeBody(array $event): string
    {
        $body = is_string($event['body'] ?? null) ? $event['body'] : '';

        if (($event['isBase64Encoded'] ?? false) !== true) {
            return $body;
        }

        // Strict mode, and an explicit false check rather than a `?:`
        // fallback. Without strict mode base64_decode() accepts invalid
        // base64 as best-effort garbage instead of reporting it; with a
        // falsy fallback a decoded "" or "0" body reads as the same
        // "empty" outcome a decode failure does — three situations that
        // must stay distinct.
        $decoded = base64_decode($body, strict: true);

        if ($decoded === false) {
            throw MalformedRequestBodyException::invalidBase64();
        }

        return $decoded;
    }

    /**
     * @return array{statusCode:int,headers:array<string,string>,cookies:list<string>,body:string,isBase64Encoded:bool}
     */
    public static function responseToPayload(ResponseInterface $response): array
    {
        // The Lambda Runtime API's poll/respond contract is strictly one
        // invocation → one response payload. Lambda response streaming
        // needs Function URLs with InvokeMode: RESPONSE_STREAM — a
        // different invocation model this next/response-polling adapter
        // doesn't implement. Abandoned before the refusal is raised: this
        // invocation never writes the body, so the request scope the
        // emitter would have resolved from is released here rather than
        // staying live across the freeze until the next invocation.
        if ($response instanceof StreamableResponseInterface) {
            $response->abandon();

            throw BrefAdapterException::streamingNotSupported();
        }

        $headers = [];
        $cookies = [];

        foreach ($response->getHeaders() as $name => $values) {
            // Payload format 2.0's own mirror of the request side above:
            // every Set-Cookie value goes into its own dedicated `cookies`
            // entry, never comma-joined into one ordinary header the way
            // every other repeated header is here. A cookie's own
            // attributes (Expires, in particular) already contain a comma,
            // so folding several cookies together the same way would
            // produce a value no client could parse back into distinct
            // cookies.
            if (strcasecmp($name, 'Set-Cookie') === 0) {
                array_push($cookies, ...$values);

                continue;
            }

            $headers[$name] = implode(', ', $values);
        }

        $body = (string) $response->getBody();
        $isBase64Encoded = !self::isValidUtf8($body);

        return [
            'statusCode' => $response->getStatusCode(),
            'headers' => $headers,
            'cookies' => $cookies,
            // The whole payload is JSON-encoded before being posted back
            // (see postResponse()), and json_encode() rejects a string
            // that isn't valid UTF-8 outright — a binary body (an image,
            // a PDF) would otherwise turn every such response into a
            // thrown JsonException instead of being served correctly.
            'body' => $isBase64Encoded ? base64_encode($body) : $body,
            'isBase64Encoded' => $isBase64Encoded,
        ];
    }

    /**
     * @return array<string,string>
     */
    private static function parseCookieHeader(string $cookieHeader): array
    {
        $params = [];

        foreach (explode(';', $cookieHeader) as $pair) {
            [$key, $value] = array_pad(explode('=', trim($pair), 2), 2, null);

            if ($key !== null && $key !== '' && $value !== null) {
                $params[$key] = $value;
            }
        }

        return $params;
    }

    /**
     * Avoids adding an ext-mbstring dependency to this package just for
     * this one check: an empty PCRE pattern with the "u" modifier matches
     * trivially against valid UTF-8 but fails outright against a string
     * that isn't, which is exactly the distinction needed here.
     */
    private static function isValidUtf8(string $value): bool
    {
        return preg_match('//u', $value) === 1;
    }

    /**
     * @return array{0:string,1:string}
     */
    private function nextInvocation(): array
    {
        $url = "http://{$this->runtimeApi}/2018-06-01/runtime/invocation/next";
        $responseHeaders = [];
        $body = $this->request($url, 'GET', null, $this->nextInvocationTimeoutSeconds, $responseHeaders);

        $requestId = null;

        foreach ($responseHeaders as $header) {
            if (str_starts_with(strtolower($header), self::RUNTIME_HEADER_PREFIX)) {
                $requestId = trim(substr($header, strlen(self::RUNTIME_HEADER_PREFIX)));
            }
        }

        if ($requestId === null) {
            throw RuntimeUnavailableException::missingEnvironmentVariable(self::class, 'Lambda-Runtime-Aws-Request-Id');
        }

        return [$requestId, $body];
    }

    /**
     * @return array<string,mixed>
     */
    private static function decodeInvocationEvent(string $body): array
    {
        // Decoded without associative: true — the only way to preserve
        // the real JSON object/list distinction at *every* level, not
        // just the top one. json_decode(..., associative: true) maps
        // both {} and [] to the identical plain PHP array, and — the
        // sharper version of the same gap — maps an object-valued field
        // like {"a":["x","y"]} and a genuinely string-valued one like
        // {"a":"x"} into shapes an is_array() check alone can't tell
        // apart either. Under associative: false, a JSON object always
        // becomes stdClass and a JSON array always becomes a plain PHP
        // array, at any depth, which is what assertValidPayloadV2Event()
        // actually validates against.
        try {
            $decoded = json_decode($body, associative: false, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw BrefAdapterException::malformedInvocationEvent($e->getMessage());
        }

        self::assertValidPayloadV2Event($decoded);

        // Only decoded as a plain array afterward, once validation above
        // has already confirmed the real shape — requestFromEvent()'s
        // own array<string,mixed> contract is unaffected by any of this.
        /** @var array<string,mixed> */
        return json_decode($body, associative: true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * The half of the protocol boundary that only the raw JSON can
     * answer: is this a payload-v2 event at all, and are its collection
     * fields the shapes that format defines? The other half — whether
     * the request it describes has a coherent identity — is
     * {@see LambdaRequestIdentity}'s, and runs inside
     * requestFromEvent() so every caller of that method gets it,
     * including the runtime conformance suite's own driver, which never
     * comes through here.
     *
     * `"version": "2.0"` is the one field that actually distinguishes a
     * genuine payload-v2 event from anything else shaped similarly: a
     * payload-format-1 (REST API/ALB) event can otherwise carry a
     * requestContext.http-shaped object of its own construction (whether
     * by coincidence or by a caller deliberately crafting one), and a
     * direct Lambda invocation can carry arbitrary JSON regardless of
     * what API Gateway itself would ever actually send — checking only
     * requestContext.http's shape, without the version discriminator,
     * would accept both.
     *
     * $decoded is whatever json_decode(..., associative: false) produced
     * — a JSON object is stdClass, a JSON array/list is a plain PHP
     * array, a scalar is itself, at every level. Every read goes through
     * objectGet() rather than direct property access, since nothing here
     * can assume $decoded, or any nested value under it, is actually an
     * object at all.
     */
    private static function assertValidPayloadV2Event(mixed $decoded): void
    {
        if (!$decoded instanceof stdClass) {
            throw BrefAdapterException::malformedInvocationEvent(
                'the decoded event is not a JSON object — API Gateway v2 events are always objects, never a list, string, number, boolean, or null.',
            );
        }

        if (self::objectGet($decoded, 'version') !== '2.0') {
            throw BrefAdapterException::malformedInvocationEvent(
                'the decoded event has no "version": "2.0" — only the API Gateway HTTP API / Lambda Function URL (payload format 2.0) event shape is supported.',
            );
        }

        $http = self::objectGet(self::objectGet($decoded, 'requestContext'), 'http');

        self::assertOptionalScalar($decoded, 'body', 'is_string', self::DESCRIPTION_STRING);
        self::assertOptionalScalar($decoded, 'isBase64Encoded', 'is_bool', 'a boolean');
        self::assertOptionalScalar($http, 'sourceIp', 'is_string', self::DESCRIPTION_STRING);

        self::assertOptionalStringMap($decoded, 'headers');
        self::assertOptionalStringMap($decoded, 'queryStringParameters');
        self::assertOptionalStringList($decoded, 'cookies');
    }

    /**
     * Reads $decoded->$key, tolerating $decoded not being a JSON object
     * at all (a JSON array, a scalar, or null) rather than assuming it
     * is one — every caller already handles a null result as "absent",
     * the same tolerance a missing key gets.
     */
    private static function objectGet(mixed $decoded, string $key): mixed
    {
        if (!$decoded instanceof stdClass || !property_exists($decoded, $key)) {
            return null;
        }

        return $decoded->$key;
    }

    /**
     * Throws unless $decoded->$field is either absent/null (every field
     * this validates is optional — payload-v2 events don't always carry
     * every one of them) or satisfies $predicate.
     *
     * @param callable(mixed): bool $predicate
     */
    private static function assertOptionalScalar(mixed $decoded, string $field, callable $predicate, string $description): void
    {
        $value = self::objectGet($decoded, $field);

        if ($value !== null && !$predicate($value)) {
            throw BrefAdapterException::malformedInvocationEvent("{$field} must be {$description} when present.");
        }
    }

    /**
     * A JSON object with string values — the real payload-v2 shape of
     * `headers`/`queryStringParameters`. Checked against the
     * associative: false decode specifically because that's what makes
     * a JSON object become stdClass rather than a plain array: it's the
     * only way to reject an array-valued header/query-parameter value
     * (payload-v2 comma-joins a multi-value header into one string
     * instead — see this class's own top docblock) or a genuinely
     * list-shaped value passed where an object was required, neither of
     * which a plain is_array() check on the associative: true decode
     * could ever tell apart from a valid string-valued object.
     *
     * `queryStringParameters` is checked even though nothing reads it:
     * an event carrying a malformed one is a malformed event, and
     * accepting it here would mean this adapter's idea of a valid
     * payload-v2 event quietly narrowed to the fields it happens to
     * use. {@see LambdaRequestIdentity} explains why the raw query
     * string is what the request is actually built from.
     */
    private static function assertOptionalStringMap(mixed $decoded, string $field): void
    {
        $value = self::objectGet($decoded, $field);

        if ($value === null) {
            return;
        }

        if (!$value instanceof stdClass) {
            throw BrefAdapterException::malformedInvocationEvent("{$field} must be an object with string values when present.");
        }

        foreach (get_object_vars($value) as $entry) {
            if (!is_string($entry)) {
                throw BrefAdapterException::malformedInvocationEvent("{$field} must be an object with string values when present.");
            }
        }
    }

    /**
     * A JSON list of strings — `cookies`' real payload-v2 shape. Checked
     * against the associative: false decode for the same reason as
     * assertOptionalStringMap(): only that decode mode makes a JSON
     * object become stdClass rather than a plain array, which is what
     * lets this reject a cookie *object* (`{"session":"abc"}`) instead
     * of accepting it as though it were a one-element cookie list.
     */
    private static function assertOptionalStringList(mixed $decoded, string $field): void
    {
        $value = self::objectGet($decoded, $field);

        if ($value === null) {
            return;
        }

        if (!is_array($value) || array_any($value, static fn (mixed $entry): bool => !is_string($entry))) {
            throw BrefAdapterException::malformedInvocationEvent("{$field} must be a list of strings when present.");
        }
    }

    /**
     * @param array{statusCode:int,headers:array<string,string>,cookies:list<string>,body:string,isBase64Encoded:bool} $payload
     */
    private function postResponse(string $requestId, array $payload): void
    {
        $url = "http://{$this->runtimeApi}/2018-06-01/runtime/invocation/{$requestId}/response";
        $this->request($url, 'POST', json_encode($payload, JSON_THROW_ON_ERROR), $this->responseTimeoutSeconds);
    }

    private function postError(string $requestId, Throwable $e): void
    {
        $url = "http://{$this->runtimeApi}/2018-06-01/runtime/invocation/{$requestId}/error";
        $this->request($url, 'POST', json_encode([
            'errorMessage' => $e->getMessage(),
            'errorType' => $e::class,
        ], JSON_THROW_ON_ERROR), $this->responseTimeoutSeconds);
    }

    /**
     * @param list<string> $responseHeaders
     */
    private function request(string $url, string $method, ?string $body, float $timeoutSeconds, array &$responseHeaders = []): string
    {
        $http_response_header = null;

        $context = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => $body !== null ? 'Content-Type: application/json' : '',
                'content' => $body ?? '',
                'ignore_errors' => true,
                'timeout' => $timeoutSeconds,
            ],
        ]);

        // A failure here (connection refused, a timeout, ...) is checked
        // immediately below and turned into a clean, typed
        // BrefAdapterException — the same "@ + clean-exception, not raw
        // warning noise beside it" treatment this project already gives
        // mysqli's/pgsql's own connection failures.
        $result = @file_get_contents($url, context: $context);

        // PHPStan sees the literal `null` assigned above and doesn't know
        // file_get_contents() populates this magic variable as a side
        // effect, so it (wrongly) considers the ?? here redundant.
        // @phpstan-ignore-next-line nullCoalesce.variable
        $responseHeaders = $http_response_header ?? [];

        // A transport failure (the Runtime API sidecar unreachable, a
        // reset connection, ...) is fatal for a Lambda worker — there's
        // no invocation to serve and nothing left to fall back to — so
        // this throws rather than returning an empty string, which would
        // be indistinguishable from a genuinely empty, successful body.
        if ($result === false) {
            throw BrefAdapterException::runtimeApiUnreachable($url);
        }

        $status = self::statusFromResponseHeaders($responseHeaders);

        if ($status === null || $status < 200 || $status >= 300) {
            throw BrefAdapterException::runtimeApiRequestFailed($url, $status, $result);
        }

        return $result;
    }

    /**
     * @param list<string> $responseHeaders
     */
    private static function statusFromResponseHeaders(array $responseHeaders): ?int
    {
        $statusLine = $responseHeaders[0] ?? null;

        if (!is_string($statusLine) || preg_match('#^HTTP/\S+\s+(\d{3})#', $statusLine, $matches) !== 1) {
            return null;
        }

        return (int) $matches[1];
    }
}
