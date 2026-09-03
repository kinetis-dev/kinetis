<?php

declare(strict_types=1);

namespace Kinetis\AwsSigV4;

use AsyncAws\Core\Configuration;
use AsyncAws\Core\Credentials\CacheProvider;
use AsyncAws\Core\Credentials\ChainProvider;
use AsyncAws\Core\Credentials\CredentialProvider;
use AsyncAws\Core\Request as AwsRequest;
use AsyncAws\Core\RequestContext;
use AsyncAws\Core\Signer\SignerV4;
use AsyncAws\Core\Stream\StringStream;
use Kinetis\AwsSigV4\Exception\SigningException;
use Kinetis\AwsSigV4\Exception\UnsignableRequestException;
use Kinetis\RevoltHttpClient\AmpHttpClientFactory;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * A PSR-18 client decorator that signs every outgoing request with AWS
 * Signature Version 4 before delegating to a wrapped PSR-18 client — the
 * signing math itself is `AsyncAws\Core\Signer\SignerV4`, already
 * battle-tested inside every AsyncAws service client, reused as-is rather
 * than reimplemented. This class's own job is narrower: convert a PSR-7
 * request to AsyncAws's own `Request` shape, resolve credentials, sign,
 * and copy the resulting headers (`Authorization`, `X-Amz-Date`, `Host`,
 * and `X-Amz-Security-Token` when a session token is present) back onto a
 * PSR-7 request. Every other header on the original request — including
 * value count and order for a repeated header — is left completely
 * untouched; see applySignedHeaders()'s own docblock for how that's
 * guaranteed rather than assumed.
 *
 * $service is the AWS signing service name (e.g. "es" for Amazon
 * OpenSearch Service, "execute-api" for API Gateway) — required, with no
 * default, since it varies per AWS service and guessing wrong produces a
 * signature that silently fails verification rather than an obvious error.
 *
 * Composes with any PSR-18 client, not only kinetis/revolt-http-client's
 * — wrap `new \Symfony\Component\HttpClient\Psr18Client(AmpHttpClientFactory::create())`
 * to keep the actual signed request non-blocking, the same mechanism
 * already proven for kinetis/storage-s3's S3Client and kinetis/queue-sqs's
 * SqsClient. Usable standalone, not only with Kinetis — this package has
 * no dependency on kinetis/framework itself.
 *
 * SigV4 signs over the body's exact bytes, so `sendRequest()` always
 * reads the request's entire body into memory as a plain PHP string —
 * more than once, not just once: withReplayableBody() reads it in full
 * to build the SpooledStream replacement, and toAwsRequest() reads that
 * replacement's own contents again to compute the signature. Peak memory
 * during a signed request is therefore a real multiple of the body's own
 * size, not bounded by it — SpooledStream's `php://temp` backing avoids
 * holding a *second, long-lived* full copy once construction finishes,
 * but does nothing about the transient copies made along the way. A
 * large body is fully buffered, not streamed, the same trade-off
 * `Kinetis\Http\Responses\FileResponse` already discloses for its own
 * non-streaming case — genuinely bounding this would need a signer that
 * hashes the body incrementally as it's copied, which AsyncAws's own
 * `SignerV4` doesn't expose a way to do.
 *
 * A *non-seekable* body (`StreamInterface::isSeekable() === false`) is
 * read from wherever its cursor already sits rather than rewound first,
 * since rewinding one is impossible by definition — supply a body
 * already positioned at its start for a non-seekable stream to be signed
 * and sent correctly. A *seekable* body's own original position is
 * restored once signing finishes (success or failure) — the stream
 * object a caller built the request with is the same one this method
 * reads, so leaving it seeked to wherever this method happened to stop
 * would be a real, visible side effect on the caller's own object.
 */
final class SigV4SigningClient implements ClientInterface
{
    private readonly SignerV4 $signer;

    private readonly Configuration $configuration;

    private readonly CredentialProvider $credentialProvider;

    /**
     * @var array{scheme: string, host: string, port: int|null, path: string}|null
     */
    private readonly ?array $parsedBaseUri;

    /**
     * $now fixes the clock used for every request this instance signs —
     * exclusively a testability hook (the AWS-published SigV4 test
     * vectors are all pinned to a fixed date), the same `??=`-optional-
     * parameter precedent as `ProjectRoot::detect()`/`AppEnvironment::detect()`.
     * `sendRequest()`'s own signature is fixed by `ClientInterface`, so
     * there's nowhere else to thread a fixed clock through — real usage
     * always leaves this `null`, giving each signed request its own
     * genuine current time.
     *
     * $baseUri fills in scheme/host/port on a request whose own URI
     * carries none — appended last, after $now. Leave null when the
     * request already carries an absolute URI. Parsed and validated
     * here, once, rather than lazily on the first relative-URI request:
     * a misconfigured endpoint is a boot-time failure, not one that
     * waits for the first real request to surface. Only "http"/"https"
     * schemes are accepted (both — an "https"-only check would reject a
     * real, common AWS-compatible local endpoint like LocalStack), and
     * userinfo/a query string/a fragment are rejected outright rather
     * than silently discarded, since parse_url()'s own result carries no
     * indication anything was dropped.
     */
    public function __construct(
        private readonly ClientInterface $client,
        string $region,
        string $service,
        ?CredentialProvider $credentialProvider = null,
        private readonly ?\DateTimeImmutable $now = null,
        ?string $baseUri = null,
    ) {
        $this->signer = new SignerV4($service, $region);
        $this->configuration = Configuration::create([]);
        // The default chain's own bootstrap calls (IMDS, an ECS/EKS
        // metadata endpoint) go through AmpHttpClientFactory::create()
        // too, so credential resolution never blocks the worker either —
        // the same non-blocking guarantee this class exists to give the
        // actual signed request.
        $this->credentialProvider = $credentialProvider
            ?? new CacheProvider(ChainProvider::createDefaultChain(AmpHttpClientFactory::create()));
        $this->parsedBaseUri = $baseUri === null ? null : self::parseBaseUri($baseUri);
    }

    /**
     * @return array{scheme: string, host: string, port: int|null, path: string}
     */
    private static function parseBaseUri(string $baseUri): array
    {
        $parts = parse_url($baseUri);

        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            throw SigningException::invalidBaseUri();
        }

        // RFC 3986 §3.1: the scheme component is case-insensitive
        // ("HTTPS://…" is exactly as valid as "https://…") — normalized
        // here, once, so both the comparison below and the value stored
        // for later use are consistent regardless of how it was written.
        $scheme = strtolower($parts['scheme']);

        if (!in_array($scheme, ['http', 'https'], true)) {
            throw SigningException::unsupportedBaseUriScheme($scheme);
        }

        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw SigningException::unsupportedBaseUriComponents();
        }

        return [
            'scheme' => $scheme,
            'host' => $parts['host'],
            'port' => $parts['port'] ?? null,
            'path' => $parts['path'] ?? '',
        ];
    }

    /**
     * Everything this method does *before* the final, delegated
     * `$this->client->sendRequest()` call — resolving the URI, capturing
     * a replayable body, resolving credentials, folding headers, and
     * signing — is wrapped in one try/catch, converting any failure from
     * any of those steps into `UnsignableRequestException`, which
     * implements PSR-18's own `RequestExceptionInterface`. `SigV4SigningClient`
     * itself implements `Psr\Http\Client\ClientInterface`, so its own
     * processing failures must be catchable as PSR-18 exceptions exactly
     * like a failure from any other PSR-18 client would be — a caller
     * that only catches `ClientExceptionInterface` around `sendRequest()`
     * must not silently miss one produced by this class specifically.
     *
     * The delegated `sendRequest()` call itself is deliberately *outside*
     * that try/catch: whatever the wrapped client throws (a real
     * `NetworkException`/`RequestException`/`ClientException`) must reach
     * the caller completely unmodified, by identity — this class must
     * never catch and reclassify a failure that was never its own.
     */
    #[\Override]
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $originalRequest = $request;

        try {
            $request = $this->resolveUri($request);
            $request = $this->withReplayableBody($request);

            $credentials = $this->credentialProvider->getCredentials($this->configuration)
                ?? throw SigningException::noCredentialsResolved();

            $foldedHeaders = self::foldHeaders($request);
            $awsRequest = $this->toAwsRequest($request, $foldedHeaders);
            // RequestContext's own 'region' option is only ever read by
            // AbstractApi::getSigner()'s signer-selection logic, which
            // this class bypasses entirely by constructing SignerV4
            // directly with $region already fixed in — confirmed by
            // reading SignerV4::sign() itself, which only ever reads
            // getCurrentDate().
            $this->signer->sign($awsRequest, $credentials, new RequestContext(['currentDate' => $this->now]));

            $signedRequest = $this->applySignedHeaders($request, $foldedHeaders, $awsRequest);
        } catch (Throwable $e) {
            throw UnsignableRequestException::causedBy($originalRequest, $e);
        }

        return $this->client->sendRequest($signedRequest);
    }

    /**
     * Fills in scheme/host/port from $baseUri when the request's own URI
     * carries none. Left as-is when the request already has a host, or
     * when $baseUri itself is null — $baseUri was already parsed and
     * validated once, at construction (see parseBaseUri()), so this only
     * ever applies an already-known-good value.
     *
     * The request's own path is joined to the base path with exactly one
     * slash, regardless of which side (if either) already supplies one —
     * PSR-7 explicitly permits a relative-reference request path with no
     * leading slash (e.g. "users", not just "/users"), and naively
     * concatenating an rtrim()'d base path directly onto that produces a
     * genuinely wrong path ("/produsers" for base path "/prod" and
     * request path "users"), not just an untidy one. An empty request
     * path preserves the normalized base path as-is, rather than adding
     * a trailing slash that was never there. The request's own query
     * string is untouched throughout — withScheme()/withHost()/
     * withPort()/withPath() each only ever replace the one component
     * named, exactly as PSR-7's own immutable withX() contract requires.
     */
    private function resolveUri(RequestInterface $request): RequestInterface
    {
        if ($this->parsedBaseUri === null || $request->getUri()->getHost() !== '') {
            return $request;
        }

        $base = $this->parsedBaseUri;

        $uri = $request->getUri()
            ->withScheme($base['scheme'])
            ->withHost($base['host']);

        if ($base['port'] !== null) {
            $uri = $uri->withPort($base['port']);
        }

        $normalizedBasePath = rtrim($base['path'], '/');
        $requestPath = $uri->getPath();

        $path = $requestPath === ''
            ? $normalizedBasePath
            : $normalizedBasePath . '/' . ltrim($requestPath, '/');

        $uri = $uri->withPath($path);

        return $request->withUri($uri);
    }

    /**
     * Replaces the request's body with a fresh SpooledStream built from
     * its full contents, so neither the signing step below nor the
     * wrapped client's own later read ever has to seek the *original*
     * stream. PSR-7 explicitly permits a non-seekable stream
     * (`StreamInterface::isSeekable()`), and `rewind()`'s own contract
     * requires it to throw when seeking fails, which is why a
     * non-seekable stream is captured this way instead of rewound.
     *
     * A seekable stream is rewound first, matching what `__toString()`'s
     * own contract already promises to attempt — so the full body is
     * always captured, not just whatever remains from wherever the
     * cursor happened to be left by earlier code — and its *original*
     * position is saved beforehand and restored afterward in a finally
     * block, so a caller's own request object is left exactly as it was
     * regardless of whether signing succeeds: the same PSR-7 stream
     * instance the caller built the request with is also the one this
     * method reads from, so rewinding/reading it is a real, visible
     * mutation to anyone still holding a reference to it, not a private
     * copy. A non-seekable stream is read from its current position
     * instead, since seeking one backward is impossible by definition —
     * the one real, disclosed limitation this leaves; see this class's
     * own docblock above.
     */
    private function withReplayableBody(RequestInterface $request): RequestInterface
    {
        $body = $request->getBody();

        if (!$body->isSeekable()) {
            return $request->withBody(new SpooledStream($body->getContents()));
        }

        $originalPosition = $body->tell();

        try {
            $body->rewind();

            return $request->withBody(new SpooledStream($body->getContents()));
        } finally {
            $body->seek($originalPosition);
        }
    }

    /**
     * A PSR-7 header may legitimately carry more than one value (a
     * repeated header, or a Cookie-style header assembled from several
     * `withAddedHeader()` calls) — AsyncAws's own `Request` has no
     * concept of that, only one string per header name, so every value
     * list is comma-joined into the single string AsyncAws expects. This
     * folded map exists *solely* as canonical input for SignerV4 to sign
     * against; it must never be used to reconstruct the caller's own
     * headers afterward (see applySignedHeaders()) — folding a caller's
     * genuinely two-valued header down to one string and writing that
     * back would silently and permanently merge the two values into one.
     *
     * @return array<string, string>
     */
    private static function foldHeaders(RequestInterface $request): array
    {
        $headers = [];

        foreach ($request->getHeaders() as $name => $values) {
            $headers[$name] = implode(', ', $values);
        }

        return $headers;
    }

    /**
     * @param array<string, string> $foldedHeaders
     */
    private function toAwsRequest(RequestInterface $request, array $foldedHeaders): AwsRequest
    {
        // $request's body is always a SpooledStream by this point (see
        // withReplayableBody()), so reading it here to compute the
        // signature can always be followed by a rewind() that's
        // guaranteed not to throw — leaving the body positioned at 0
        // again for the wrapped client's own later read, exactly as
        // before this method ever touched it.
        $body = $request->getBody()->getContents();
        $request->getBody()->rewind();

        $awsRequest = new AwsRequest($request->getMethod(), '', [], $foldedHeaders, StringStream::create($body));
        $awsRequest->setEndpoint((string) $request->getUri());

        return $awsRequest;
    }

    /**
     * Applies only the headers `SignerV4::sign()` itself actually added
     * or changed — determined by comparing what comes back against
     * $foldedHeaders (the exact map handed to it as input), not by
     * copying every header back wholesale. A header the signer never
     * touched keeps the caller's own original PSR-7 value list — value
     * count, order, and bytes — completely untouched; only a header
     * whose folded value now differs (new, or a different value) is
     * written onto $request, always as a single value via withHeader(),
     * which matches how AsyncAws's own Request stores every header
     * (one string, never a list) and so is exactly the shape any header
     * it sets or rewrites can produce.
     *
     * Deliberately not name-hardcoded to Authorization/X-Amz-Date/Host/
     * X-Amz-Security-Token: SignerV4::sign() also conditionally sets
     * X-Amz-Content-Sha256 for a streaming body (never reached by this
     * class, which always hands it a fully-materialized SpooledStream —
     * see toAwsRequest()'s own docblock), and a hardcoded list would
     * silently stop matching reality the moment a future SignerV4
     * version changes what it signs with. Comparing against the actual
     * folded input instead means this stays correct regardless of which
     * headers the signer decides to touch, without needing to duplicate
     * SignerV4's own internal logic here to predict it.
     *
     * This class only ever calls Signer::sign(), never Signer::presign()
     * — the only place SignerV4 itself removes a header
     * (convertHeaderToQuery(), moving x-amz-* headers into the query
     * string for a presigned URL) is exclusively on the presign() path,
     * confirmed directly from its own source, so there is no header a
     * genuine sign() call can remove out from under this method; nothing
     * here needs to reconcile a header present in $foldedHeaders but
     * absent from $signed->getHeaders().
     *
     * @param array<string, string> $foldedHeaders
     */
    private function applySignedHeaders(RequestInterface $request, array $foldedHeaders, AwsRequest $signed): RequestInterface
    {
        foreach ($signed->getHeaders() as $name => $value) {
            if (($foldedHeaders[$name] ?? null) === $value) {
                continue;
            }

            $request = $request->withHeader($name, $value);
        }

        return $request;
    }
}
