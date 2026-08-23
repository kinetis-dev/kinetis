<?php

declare(strict_types=1);

namespace Kinetis\BrefAdapter\Tests\Conformance;

use Kinetis\BrefAdapter\BrefLambdaAdapter;
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

    #[\Override]
    public function dispatch(WireRequest $request, ResponseSpec $response): Outcome
    {
        $observed = null;
        $handler = $response->asHandler(static function (ObservedRequest $seen) use (&$observed): void {
            $observed = $seen;
        });

        try {
            $payload = BrefLambdaAdapter::handleEvent(self::eventFor($request), $handler);
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
        // No SAPI size limit exists here; what this adapter cannot parse
        // is a multipart body with no usable boundary.
        return new WireRequest(
            'POST',
            '/',
            headers: [['Content-Type', 'multipart/form-data; boundary=----XYZ']],
            body: 'this is not a multipart body at all',
        );
    }

    /**
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

        $queryParams = [];
        parse_str($request->queryString, $queryParams);

        $isBinary = preg_match('//u', $request->body) !== 1;

        $event = [
            'version' => '2.0',
            'rawPath' => $request->path,
            'rawQueryString' => $request->queryString,
            'headers' => $headers,
            'queryStringParameters' => $queryParams,
            'requestContext' => ['http' => ['method' => $request->method, 'sourceIp' => self::SOURCE_IP]],
            'body' => $isBinary ? base64_encode($request->body) : $request->body,
            'isBase64Encoded' => $isBinary,
        ];

        if ($request->cookies !== []) {
            $event['cookies'] = $request->cookies;
        }

        return $event;
    }
}
