<?php

declare(strict_types=1);

namespace Kinetis\AwsSigV4\Exception;

use Kinetis\AwsSigV4\SpooledStream;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Message\RequestInterface;
use RuntimeException;
use SensitiveParameter;
use UnexpectedValueException;

/**
 * What every per-request failure `SigV4SigningClient` raises has in
 * common. Not `final` — it exists to be extended, and PSR-18 splits its
 * failures in two, so {@see RequestFailureException} and
 * {@see NetworkFailureException} sit underneath it and the concrete,
 * `final` failures sit under those.
 *
 * PSR-18 accessor: `getRequest()` returns the request the caller handed
 * to `sendRequest()`, never a resolved, normalized or signed one, so a
 * signed `Authorization` or `X-Amz-Security-Token` header has no path
 * out through it.
 *
 * Message and cause: the message is one of this namespace's fixed
 * category strings, and no cause is chained. A credential provider, URI
 * parser, signer, or transport `Throwable` carries endpoint text, token
 * file contents, OpenSSL detail, or the signed request itself in its own
 * message and trace, and a chained cause reaches every ordinary error
 * channel — `(string) $e`, PSR-3 normalization, a `getPrevious()` walk.
 * Those causes are discarded rather than stored; diagnose transport
 * problems through the transport's own logger.
 *
 * Serialization: on top of what {@see SafeSerialization} drops, the
 * caller's request is replaced with a copy carrying its method, scheme,
 * host, port and path — and nothing else. No headers, no body, no
 * userinfo, no query string, no fragment. That is the whole of the safe
 * contract: enough to name which endpoint failed, never enough to carry
 * a credential. A live instance still returns the caller's own request
 * object from `getRequest()`.
 */
abstract class ClientFailureException extends RuntimeException implements ClientExceptionInterface
{
    use SafeSerialization;

    protected function __construct(
        #[SensitiveParameter] private readonly RequestInterface $request,
        string $message,
    ) {
        parent::__construct($message);
    }

    public function getRequest(): RequestInterface
    {
        return $this->request;
    }

    /**
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        $state = $this->baseState();
        $state['request'] = self::withoutSecrets($this->request);

        return $state;
    }

    /**
     * The one place `$request` is written outside the constructor:
     * `unserialize()` builds the object without calling one, leaving the
     * property uninitialized for this method to initialize once. A live
     * instance keeps the caller's own request for the whole of its life;
     * a restored one carries only what __serialize() let through.
     *
     * @param array<string, mixed> $data
     */
    public function __unserialize(#[SensitiveParameter] array $data): void
    {
        $this->restoreBaseState($data);

        $request = $data['request'] ?? null;

        if (!$request instanceof RequestInterface) {
            throw new UnexpectedValueException('The serialized payload does not carry a PSR-7 request.');
        }

        $this->request = $request;
    }

    private static function withoutSecrets(#[SensitiveParameter] RequestInterface $request): RequestInterface
    {
        $stripped = $request
            ->withUri($request->getUri()->withUserInfo('')->withQuery('')->withFragment(''))
            ->withBody(new SpooledStream(''));

        foreach (array_keys($stripped->getHeaders()) as $name) {
            $stripped = $stripped->withoutHeader((string) $name);
        }

        return $stripped;
    }
}
