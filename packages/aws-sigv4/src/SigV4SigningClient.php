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
use Kinetis\RevoltHttpClient\AmpHttpClientFactory;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * A PSR-18 client decorator that signs every outgoing request with AWS
 * Signature Version 4 before delegating to a wrapped PSR-18 client — the
 * signing math itself is `AsyncAws\Core\Signer\SignerV4`, already
 * battle-tested inside every AsyncAws service client, reused as-is rather
 * than reimplemented. This class's own job is narrower: convert a PSR-7
 * request to AsyncAws's own `Request` shape, resolve credentials, sign,
 * and copy the resulting headers (`Authorization`, `X-Amz-Date`, `Host`,
 * and `X-Amz-Security-Token` when a session token is present) back onto a
 * PSR-7 request.
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
     * request already carries an absolute URI.
     */
    public function __construct(
        private readonly ClientInterface $client,
        string $region,
        string $service,
        ?CredentialProvider $credentialProvider = null,
        private readonly ?\DateTimeImmutable $now = null,
        private readonly ?string $baseUri = null,
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
    }

    #[\Override]
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $request = $this->resolveUri($request);
        $request = $this->withReplayableBody($request);

        $credentials = $this->credentialProvider->getCredentials($this->configuration)
            ?? throw SigningException::noCredentialsResolved();

        $awsRequest = $this->toAwsRequest($request);
        // RequestContext's own 'region' option is only ever read by
        // AbstractApi::getSigner()'s signer-selection logic, which this
        // class bypasses entirely by constructing SignerV4 directly with
        // $region already fixed in — confirmed by reading SignerV4::sign()
        // itself, which only ever reads getCurrentDate().
        $this->signer->sign($awsRequest, $credentials, new RequestContext(['currentDate' => $this->now]));

        return $this->client->sendRequest($this->applySignedHeaders($request, $awsRequest));
    }

    /**
     * Fills in scheme/host/port from $baseUri when the request's own URI
     * carries none. Left as-is when the request already has a host, or
     * when $baseUri itself is null.
     */
    private function resolveUri(RequestInterface $request): RequestInterface
    {
        if ($this->baseUri === null || $request->getUri()->getHost() !== '') {
            return $request;
        }

        $parts = parse_url($this->baseUri);

        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            throw SigningException::invalidBaseUri($this->baseUri);
        }

        $uri = $request->getUri()
            ->withScheme($parts['scheme'])
            ->withHost($parts['host']);

        if (isset($parts['port'])) {
            $uri = $uri->withPort($parts['port']);
        }

        if (isset($parts['path']) && $parts['path'] !== '') {
            $uri = $uri->withPath(rtrim($parts['path'], '/') . $uri->getPath());
        }

        return $request->withUri($uri);
    }

    /**
     * Replaces the request's body with a fresh SpooledStream built from
     * its full contents, so neither the signing step below nor the
     * wrapped client's own later read ever has to seek the *original*
     * stream. PSR-7 explicitly permits a non-seekable stream
     * (`StreamInterface::isSeekable()`), and `rewind()`'s own contract
     * requires it to throw when seeking fails — which an unconditional
     * rewind() after reading, the previous approach here, would do for
     * exactly that stream.
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
     * copy — confirmed by a real reproduction: a caller-positioned
     * stream came back at a different offset after a first version of
     * this method that never restored it. A non-seekable stream is read
     * from its current position instead, since seeking one backward is
     * impossible by definition — the one real, disclosed limitation this
     * leaves; see this class's own docblock above.
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

    private function toAwsRequest(RequestInterface $request): AwsRequest
    {
        $headers = [];

        foreach ($request->getHeaders() as $name => $values) {
            $headers[$name] = implode(', ', $values);
        }

        // $request's body is always a SpooledStream by this point (see
        // withReplayableBody()), so reading it here to compute the
        // signature can always be followed by a rewind() that's
        // guaranteed not to throw — leaving the body positioned at 0
        // again for the wrapped client's own later read, exactly as
        // before this method ever touched it.
        $body = $request->getBody()->getContents();
        $request->getBody()->rewind();

        $awsRequest = new AwsRequest($request->getMethod(), '', [], $headers, StringStream::create($body));
        $awsRequest->setEndpoint((string) $request->getUri());

        return $awsRequest;
    }

    private function applySignedHeaders(RequestInterface $request, AwsRequest $signed): RequestInterface
    {
        foreach ($signed->getHeaders() as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return $request;
    }
}
