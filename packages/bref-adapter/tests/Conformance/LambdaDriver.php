<?php

declare(strict_types=1);

namespace Kinetis\BrefAdapter\Tests\Conformance;

use Kinetis\BrefAdapter\BrefLambdaAdapter;
use Kinetis\Http\Form\FormLimits;
use Kinetis\Testing\Runtime\AdapterRejection;
use Kinetis\Testing\Runtime\ObservedRequest;
use Kinetis\Testing\Runtime\Outcome;
use Kinetis\Testing\Runtime\ResponseSpec;
use Kinetis\Testing\Runtime\RuntimeAdapterDriver;
use Kinetis\Testing\Runtime\WireRequest;
use Kinetis\Testing\Runtime\WireResponse;
use Throwable;

/**
 * Drives BrefLambdaAdapter::handleEvent() in-process — the same code
 * run() drives per invocation, minus the Runtime API poll/post around
 * it, which the end-to-end tests cover against a real fake server.
 *
 * The event is built the way API Gateway's payload format 2.0 would
 * build it from the same wire request: repeated headers folded into one
 * comma-joined value (API Gateway does that before the event exists),
 * cookies as the top-level `cookies` list, a body that isn't valid
 * UTF-8 base64-encoded with `isBase64Encoded: true`.
 */
final class LambdaDriver implements RuntimeAdapterDriver
{
    private const string SOURCE_IP = '203.0.113.7';

    /** The gateway's own domain, for a request that named no authority itself. */
    private const string DOMAIN_NAME = 'kinetis.execute-api.eu-west-1.amazonaws.com';

    /**
     * An HTTP API and a Lambda Function URL are TLS-only; there is no
     * plaintext listener to serve a request over.
     */
    private const string SCHEME = 'https';

    /**
     * The default policy, stated rather than defaulted: an adapter is
     * constructed with the byte ceiling its application configured, and
     * a driver stands in for that application.
     */
    private static function limits(): FormLimits
    {
        return new FormLimits(FormLimits::DEFAULT_MAX_BODY_BYTES);
    }

    #[\Override]
    public function dispatch(WireRequest $request, ResponseSpec $response): Outcome
    {
        $observed = null;
        $handler = $response->asHandler(static function (ObservedRequest $seen) use (&$observed): void {
            $observed = $seen;
        });

        try {
            $payload = BrefLambdaAdapter::handleEvent(self::eventFor($request), $handler, self::limits());
            // The real loop JSON-encodes the payload before posting it;
            // "this response survives JSON encoding" is the guarantee the
            // binary-body path exists for, so prove it here too.
            json_encode($payload, JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            return new Outcome($observed, new AdapterRejection($e::class, $e->getMessage()));
        }

        return new Outcome($observed, self::wireResponseFromPayload($payload));
    }

    /**
     * The payload as API Gateway hands it to the client: base64 undone
     * where the flag says so, the `cookies` list back to `Set-Cookie`s.
     *
     * @param array{statusCode:int,headers:array<string,string>,cookies:list<string>,body:string,isBase64Encoded:bool} $payload
     */
    public static function wireResponseFromPayload(array $payload): WireResponse
    {
        $headers = [];

        foreach ($payload['headers'] as $name => $value) {
            $headers[] = [$name, $value];
        }

        $body = $payload['isBase64Encoded']
            ? (string) base64_decode($payload['body'], strict: true)
            : $payload['body'];

        return new WireResponse($payload['statusCode'], $headers, $payload['cookies'], $body);
    }

    #[\Override]
    public function expectedClientIp(): string
    {
        return self::SOURCE_IP;
    }

    #[\Override]
    public function supportsStreaming(): bool
    {
        // The Runtime API's poll/respond contract is one invocation, one
        // payload — responseToPayload() refuses a streamed response.
        return false;
    }

    #[\Override]
    public function unparseableFormRequest(): WireRequest
    {
        // A boundary this adapter's own parser can find nowhere in the
        // body: the parts never begin, so there is no form to build.
        return new WireRequest(
            'POST',
            '/',
            headers: [['Content-Type', 'multipart/form-data; boundary=----XYZ']],
            body: 'this is not a multipart body at all',
        );
    }

    #[\Override]
    public function expectedScheme(): string
    {
        return self::SCHEME;
    }

    #[\Override]
    public function preservesNumericHeaderNames(): bool
    {
        return true;
    }

    #[\Override]
    public function preservesCookieOrder(): bool
    {
        // Payload format 2.0 carries cookies as an ordered JSON list.
        return true;
    }

    #[\Override]
    public function trustsTheConnectingClient(): bool
    {
        // There is no connecting client here: an invocation arrives over
        // the Runtime API, and `x-forwarded-proto` is API Gateway's own
        // field on an event it built. The gateway is the edge, always —
        // and the value is still validated rather than believed, which
        // LambdaRequestIdentity does.
        return true;
    }

    /**
     * The event API Gateway would build from this wire request. Every
     * field here is one API Gateway sets itself, filled the way it
     * really fills it — `domainName` from the authority the client
     * addressed, `x-forwarded-proto`/`x-forwarded-port` from the
     * termination it did, repeated headers already folded, cookies
     * lifted out of the headers into their own list, a body that isn't
     * valid UTF-8 base64-encoded and flagged.
     *
     * Nothing here computes an expected result. The suite's expectations
     * come from the wire request and from the contract; this only
     * translates that request into the shape this environment delivers
     * it in.
     *
     * @return array<string, mixed>
     */
    private static function eventFor(WireRequest $request): array
    {
        $headers = [];

        foreach ($request->headers as [$name, $value]) {
            $key = strtolower($name);
            $headers[$key] = isset($headers[$key]) ? "{$headers[$key]}, {$value}" : $value;
        }

        // API Gateway forwards the client's own Content-Length; the body
        // field carries the bytes, but the header is what the application
        // gets to read before touching them. Only synthesized when the
        // request didn't declare one — a supplied value is forwarded as
        // given, which is what the suite checks.
        if ($request->body !== '' && !isset($headers['content-length'])) {
            $headers['content-length'] = (string) strlen($request->body);
        }

        [$domain, $port] = self::authority($headers);
        $headers['x-forwarded-proto'] ??= self::SCHEME;

        if ($port !== null) {
            $headers['x-forwarded-port'] = $port;
        }

        $queryParams = [];
        parse_str($request->queryString, $queryParams);

        $isBinary = preg_match('//u', $request->body) !== 1;

        $event = [
            'version' => '2.0',
            'rawPath' => $request->path,
            'rawQueryString' => $request->queryString,
            'headers' => $headers,
            // Present because a real event carries it, and not what the
            // adapter builds query parameters from — see
            // Kinetis\BrefAdapter\LambdaRequestIdentity.
            'queryStringParameters' => array_filter($queryParams, is_string(...)),
            'requestContext' => [
                'domainName' => $domain,
                'http' => [
                    'method' => $request->method,
                    'protocol' => 'HTTP/1.1',
                    'sourceIp' => self::SOURCE_IP,
                ],
            ],
            'body' => $isBinary ? base64_encode($request->body) : $request->body,
            'isBase64Encoded' => $isBinary,
        ];

        if ($request->cookies !== []) {
            $event['cookies'] = $request->cookies;
        }

        return $event;
    }

    /**
     * The domain the client addressed, and the port it named if it named
     * one — API Gateway takes `domainName` from exactly that and
     * republishes the port as `x-forwarded-port`. A request that
     * declared no `Host` reached the gateway's own domain.
     *
     * @param array<string, string> $headers
     * @return array{0: string, 1: ?string}
     */
    private static function authority(array $headers): array
    {
        $host = $headers['host'] ?? self::DOMAIN_NAME;

        if (preg_match('/^([^:]+):(\d+)$/', $host, $matches) === 1) {
            return [$matches[1], $matches[2]];
        }

        return [$host, null];
    }
}
