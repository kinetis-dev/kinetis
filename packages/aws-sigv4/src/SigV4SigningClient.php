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
use Kinetis\AwsSigV4\Exception\NetworkFailureException;
use Kinetis\AwsSigV4\Exception\SigningException;
use Kinetis\AwsSigV4\Exception\TransportFailureException;
use Kinetis\AwsSigV4\Exception\UnsignableRequestException;
use Kinetis\AwsSigV4\Exception\UntrustedOriginException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;
use SensitiveParameter;
use Symfony\Component\HttpClient\Psr18Client;
use Throwable;

/**
 * A PSR-18 client that signs every outgoing request with AWS Signature
 * Version 4 and sends it to one configured origin. The signing math is
 * `AsyncAws\Core\Signer\SignerV4`, the signer every AsyncAws service
 * client already uses; this class converts a PSR-7 request into
 * AsyncAws's own `Request` shape, resolves credentials, signs, and
 * copies the resulting headers (`Authorization`, `X-Amz-Date`, `Host`,
 * and `X-Amz-Security-Token` when a session token is present) back onto
 * a PSR-7 request. Every other header — value count and order for a
 * repeated header included — is left as it was; see
 * applySignedHeaders().
 *
 * $service is the AWS signing service name ("es" for Amazon OpenSearch
 * Service, "execute-api" for API Gateway). It has no default: it varies
 * per service, and a wrong one produces a signature that fails
 * verification rather than an error.
 *
 * ## One trusted origin
 *
 * $origin is mandatory and names the only scheme/host/port a signature
 * from this client may reach, and the only path prefix it may reach it
 * under — see {@see TrustedOrigin} for the grammar it must satisfy and
 * how origins are compared. A relative request is resolved against it;
 * an absolute request must already name it. Every target that does not —
 * a different host, a different port, an `http` target under an `https`
 * origin, a `//host/path` network-path reference, userinfo, a control
 * character or backslash, a malformed percent escape, a path outside the
 * base path — is rejected with `UntrustedOriginException` before the
 * credential provider is called, before the body is read, and before
 * the transport is touched.
 *
 * ## What is signed is what is sent
 *
 * The target is put into its wire form by {@see WireTarget} before the
 * origin check and before signing, and the URI that reaches the
 * transport is built by this package from the origin's own canonical
 * authority. An HTTP client that resolves `/a/../b` or decodes `/%7Efoo`
 * after signing would otherwise send a target the signature was never
 * computed over, and dot segments — spelled `..` or `%2E%2E` — would
 * reach past the configured base path with a signature that says
 * nothing about where they landed. Origin and base path are both
 * checked against that final form.
 *
 * ## Redirects are terminal, and one send is one request
 *
 * The transport is {@see SignedTransport}, this package's own, wrapped
 * in Symfony's PSR-18 adapter: one delegate call per request over a bare
 * AMPHP client, `max_redirects => 0` written into every request it
 * forwards, and no interceptor that could retry one or follow a
 * `Location`. A 3xx is returned as the response. Nothing is re-signed
 * and no second request is made, so a `Location` cannot carry an
 * `Authorization` or `X-Amz-Security-Token` header off the configured
 * origin. The default credential chain — the ECS/EKS/IMDS lookups —
 * runs on that same transport, so a pod-identity or metadata token
 * cannot follow a `Location` either.
 *
 * A caller configures that transport's default options and nothing else.
 * It has no public constructor, so no client of a caller's own goes
 * underneath a signature: a decorator that replays a signed request, or
 * sets `max_redirects` back on its way down, belongs above this class,
 * where a replay costs a fresh signature.
 *
 * ## Failures carry no secrets
 *
 * Every failure this class raises is one of the fixed-message types in
 * `Kinetis\AwsSigV4\Exception`, with no cause chained and the caller's
 * own request — never a resolved, normalized or signed one — behind
 * `getRequest()`. A transport failure is converted too: Symfony's PSR-18
 * adapter reports one through an exception holding the signed request.
 * PSR-18's own split survives the conversion — a connection that could
 * not be made, was lost or timed out stays a `NetworkExceptionInterface`
 * and everything else stays a `RequestExceptionInterface` — because a
 * caller decides whether to retry on exactly that distinction.
 *
 * ## What is synchronous
 *
 * A request through the transport, and the credential chain's own
 * ECS/EKS/IMDS lookups, suspend the calling Fiber rather than blocking
 * it: {@see SignedTransport} is AMPHP-backed. Everything else is
 * synchronous PHP work on the calling thread: the shared credentials and
 * config files, an SSO cache file, and a web identity token file are
 * read with blocking filesystem calls, and capturing and hashing the
 * request body is CPU work.
 *
 * SigV4 signs over the body's exact bytes, so `sendRequest()` reads the
 * whole body into memory as a plain string more than once —
 * withReplayableBody() reads it to build the SpooledStream replacement,
 * and toAwsRequest() reads that replacement again to compute the
 * signature. Peak memory for a signed request is a multiple of the
 * body's size; `SpooledStream`'s `php://temp` backing keeps the
 * long-lived copy off the heap past 2MB but does nothing about the
 * transient ones. There is no size ceiling on what this class will
 * sign; a ceiling on what may be uploaded to S3 belongs to
 * `kinetis/storage-s3`, where such an upload is built.
 *
 * A non-seekable body (`StreamInterface::isSeekable() === false`) is
 * read from wherever its cursor sits, since rewinding one is impossible
 * — supply such a body positioned at its start. A seekable body's
 * original position is restored once signing finishes, success or
 * failure: the stream the caller built the request with is the one this
 * class reads.
 */
final class SigV4SigningClient implements ClientInterface
{
    /**
     * Region and service names are bounded, nonblank ASCII: a letter or
     * digit, then up to 63 more letters, digits, ".", "-" or "_". Every
     * AWS region ("us-east-1", "cn-north-1") and signing name ("es",
     * "execute-api") fits, and a value that does not cannot reach the
     * credential scope string a signature is built from.
     *
     * Anchored with `\z`, not `$`: `$` also matches before a final
     * newline, so `"us-east-1\n"` would otherwise pass and be signed
     * into a credential scope.
     */
    private const string NAME_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._\-]{0,63}\z/';

    private readonly TrustedOrigin $origin;

    private readonly SignerV4 $signer;

    private readonly Configuration $configuration;

    private readonly CredentialProvider $credentialProvider;

    private readonly ClientInterface $client;

    /**
     * $now fixes the clock for every request this instance signs — a
     * testability hook, since `sendRequest()`'s signature is fixed by
     * `ClientInterface` and the AWS-published SigV4 test vectors are
     * pinned to a fixed date. Real usage leaves it null.
     *
     * $transport is what the signed request travels on:
     * `SignedTransport::create()` carrying default options — a timeout,
     * headers the endpoint always needs — or one answering from a
     * function under test. Its type is that class rather than
     * `HttpClientInterface` because every way of building one performs
     * one delegate call per request with `max_redirects => 0` and no
     * interceptor above it, so nothing that retries or follows a
     * `Location` fits underneath a signature. Null builds the default,
     * used for both the signed request and the default credential chain.
     */
    public function __construct(
        #[SensitiveParameter] string $origin,
        string $region,
        string $service,
        #[SensitiveParameter] ?CredentialProvider $credentialProvider = null,
        private readonly ?\DateTimeImmutable $now = null,
        #[SensitiveParameter] ?SignedTransport $transport = null,
    ) {
        if (preg_match(self::NAME_PATTERN, $region) !== 1) {
            throw SigningException::invalidRegion();
        }

        if (preg_match(self::NAME_PATTERN, $service) !== 1) {
            throw SigningException::invalidService();
        }

        $this->origin = TrustedOrigin::parse($origin);
        $this->signer = new SignerV4($service, $region);
        $this->configuration = Configuration::create([]);

        $transport ??= SignedTransport::create();
        $psr17 = new Psr17Factory();

        $this->client = new Psr18Client($transport, $psr17, $psr17);
        $this->credentialProvider = $credentialProvider
            ?? new CacheProvider(ChainProvider::createDefaultChain($transport));
    }

    /**
     * The order is the guarantee: the target is normalized and checked
     * first, so a rejected one costs no credential resolution, no body
     * read, and no network access. Each following step converts whatever
     * it can throw into one fixed-message exception naming that step,
     * dropping the cause; the transport call is wrapped the same way,
     * since Symfony's PSR-18 adapter would otherwise hand the caller the
     * signed request through `getRequest()` — and its own split between a
     * network failure and a request failure is preserved across that
     * conversion.
     */
    #[\Override]
    public function sendRequest(#[SensitiveParameter] RequestInterface $request): ResponseInterface
    {
        $onOrigin = self::pointedAt($request, $this->trustedTarget($request));

        try {
            $prepared = $this->withReplayableBody($onOrigin);
        } catch (Throwable) {
            throw UnsignableRequestException::bodyCaptureFailed($request);
        }

        try {
            $credentials = $this->credentialProvider->getCredentials($this->configuration);
        } catch (Throwable) {
            throw UnsignableRequestException::credentialProviderFailed($request);
        }

        if ($credentials === null) {
            throw UnsignableRequestException::credentialsUnavailable($request);
        }

        try {
            $foldedHeaders = self::foldHeaders($prepared);
            $awsRequest = $this->toAwsRequest($prepared, $foldedHeaders);
            // RequestContext's own 'region' option is read only by
            // AbstractApi::getSigner()'s signer selection, which this
            // class bypasses by constructing SignerV4 with $region
            // already fixed in; SignerV4::sign() reads getCurrentDate().
            $this->signer->sign($awsRequest, $credentials, new RequestContext(['currentDate' => $this->now]));

            $signed = $this->applySignedHeaders($prepared, $foldedHeaders, $awsRequest);
        } catch (Throwable) {
            throw UnsignableRequestException::signingFailed($request);
        }

        try {
            return $this->client->sendRequest($signed);
        } catch (NetworkExceptionInterface) {
            throw NetworkFailureException::forRequest($request);
        } catch (Throwable) {
            throw TransportFailureException::forRequest($request);
        }
    }

    /**
     * A `%` that does not begin a two-hex-digit escape.
     *
     * The path and the query are asked separately: a `%` at the end of
     * one is malformed however the next one begins, and joining them
     * first would let a query opening with two hex digits complete a
     * path's dangling escape into something that reads as well-formed.
     */
    private static function hasMalformedEscape(#[SensitiveParameter] string $component): bool
    {
        return preg_match('/%(?![0-9A-Fa-f]{2})/', $component) === 1;
    }

    /**
     * Points $request at the trusted target, or throws.
     *
     * PSR-7 requires no agreement between a URI's components and the
     * string a request renders them as, and the transport sends the
     * string — so a request that will not take the target whole would be
     * signed for one target and sent to another. Both ways that can
     * happen, a `withUri()` that throws and one that answers with
     * something else, end here rather than in a signature.
     */
    private static function pointedAt(
        #[SensitiveParameter] RequestInterface $request,
        #[SensitiveParameter] UriInterface $target,
    ): RequestInterface {
        try {
            $onOrigin = $request->withUri($target);
        } catch (Throwable) {
            throw UntrustedOriginException::forRequest($request);
        }

        if ((string) $onOrigin->getUri() !== (string) $target) {
            throw UntrustedOriginException::forRequest($request);
        }

        return $onOrigin;
    }

    /**
     * Returns the absolute, on-origin URI to sign and send, or throws.
     *
     * A target carrying a control character, a backslash, userinfo, or a
     * malformed percent escape is rejected before anything is compared:
     * PSR-7 implementations disagree on what those mean, and a target
     * whose authority one parser reads differently from another cannot
     * be checked against an origin at all. What remains is
     * unambiguous — a target with a host must name the configured origin
     * exactly (which a network-path reference, having no scheme, never
     * does), and a target without one must have no scheme or port of its
     * own before its path is joined onto the base path.
     *
     * The path and query are then put into wire form, and the base-path
     * check runs on that form rather than on what arrived: `/prod/../..`
     * and `/prod/%2E%2E/%2E%2E` are the same target as `/`, and only the
     * normalized spelling says so. A fragment is dropped — it never
     * reaches an HTTP request line, so signing over one would sign
     * something that is not sent.
     *
     * The returned URI is built from the origin's own canonical scheme,
     * host and port, so the authority the signature covers is the
     * configured one however the caller spelled it.
     */
    private function trustedTarget(#[SensitiveParameter] RequestInterface $request): UriInterface
    {
        $uri = $request->getUri();

        if (preg_match('/[\x00-\x1F\x7F\\\\]/', (string) $uri) === 1 || $uri->getUserInfo() !== '') {
            throw UntrustedOriginException::forRequest($request);
        }

        if (self::hasMalformedEscape($uri->getPath()) || self::hasMalformedEscape($uri->getQuery())) {
            throw UntrustedOriginException::forRequest($request);
        }

        if ($uri->getHost() !== '') {
            if (!$this->origin->matches($uri)) {
                throw UntrustedOriginException::forRequest($request);
            }

            $path = $uri->getPath();
        } else {
            if ($uri->getScheme() !== '' || $uri->getPort() !== null) {
                throw UntrustedOriginException::forRequest($request);
            }

            $path = $this->origin->join($uri->getPath());
        }

        $path = WireTarget::normalizePath($path);

        if (!$this->origin->coversPath($path)) {
            throw UntrustedOriginException::forRequest($request);
        }

        return $this->origin->targetFor($path, WireTarget::normalizeEncoding($uri->getQuery()));
    }

    /**
     * Replaces the request's body with a fresh SpooledStream built from
     * its full contents, so neither the signing step nor the transport's
     * own later read has to seek the original stream. PSR-7 permits a
     * non-seekable stream, and `rewind()` must throw when seeking fails,
     * which is why one is captured this way rather than rewound.
     *
     * A seekable stream is rewound first, so the full body is captured
     * rather than whatever remains from wherever the cursor was left,
     * and its original position is saved beforehand and restored in a
     * finally block: the caller holds a reference to that same stream
     * object, so reading it is a visible mutation of theirs. A
     * non-seekable stream is read from its current position.
     */
    private function withReplayableBody(#[SensitiveParameter] RequestInterface $request): RequestInterface
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
     * A PSR-7 header may carry more than one value; AsyncAws's `Request`
     * holds one string per name, so every value list is comma-joined
     * into the single string it expects. This folded map is canonical
     * input for SignerV4 only. Writing it back would merge a caller's
     * two-valued header into one — see applySignedHeaders().
     *
     * @return array<string, string>
     */
    private static function foldHeaders(#[SensitiveParameter] RequestInterface $request): array
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
    private function toAwsRequest(
        #[SensitiveParameter] RequestInterface $request,
        #[SensitiveParameter] array $foldedHeaders,
    ): AwsRequest {
        // $request's body is a SpooledStream by this point (see
        // withReplayableBody()), so the read below can be followed by a
        // rewind() that cannot throw, leaving the body at 0 for the
        // transport's own later read.
        $body = $request->getBody()->getContents();
        $request->getBody()->rewind();

        $awsRequest = new AwsRequest($request->getMethod(), '', [], $foldedHeaders, StringStream::create($body));
        $awsRequest->setEndpoint((string) $request->getUri());

        return $awsRequest;
    }

    /**
     * Applies only the headers `SignerV4::sign()` added or changed,
     * found by comparing what comes back against $foldedHeaders (the map
     * handed to it as input) rather than by copying every header back. A
     * header the signer never touched keeps the caller's own value list
     * — count, order, and bytes; a header whose folded value differs
     * after signing is written with withHeader() as a single value,
     * which is the shape AsyncAws's Request stores every header in.
     *
     * Not name-hardcoded to Authorization/X-Amz-Date/Host/
     * X-Amz-Security-Token: SignerV4::sign() also sets
     * X-Amz-Content-Sha256 for a streaming body, and a fixed list stops
     * matching the signer the moment it signs with something else.
     *
     * This class calls Signer::sign(), never Signer::presign(), and the
     * only place SignerV4 removes a header — convertHeaderToQuery(),
     * moving x-amz-* headers into the query string — is on the presign()
     * path, so nothing here reconciles a header present in
     * $foldedHeaders and absent from $signed->getHeaders().
     *
     * @param array<string, string> $foldedHeaders
     */
    private function applySignedHeaders(
        #[SensitiveParameter] RequestInterface $request,
        #[SensitiveParameter] array $foldedHeaders,
        #[SensitiveParameter] AwsRequest $signed,
    ): RequestInterface {
        foreach ($signed->getHeaders() as $name => $value) {
            if (($foldedHeaders[$name] ?? null) === $value) {
                continue;
            }

            $request = $request->withHeader($name, $value);
        }

        return $request;
    }
}
