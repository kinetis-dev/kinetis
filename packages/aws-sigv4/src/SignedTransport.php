<?php

declare(strict_types=1);

namespace Kinetis\AwsSigV4;

use Amp\Http\Client\DelegateHttpClient;
use Amp\Http\Client\PooledHttpClient;
use Kinetis\RevoltHttpClient\AmpHttpClientFactory;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

/**
 * The only transport a signed request travels on, and the one the
 * credential chain's own ECS/EKS/IMDS lookups use. It exists so that
 * "one network attempt, no redirect" is a property of an object this
 * package constructs, rather than a claim about a client handed in from
 * outside.
 *
 * ## One attempt
 *
 * request() calls its delegate once and answers with what that call
 * returned, whatever the status. Nothing here reads a `Location`, and
 * nothing here sends twice.
 *
 * The delegate create() builds is a Symfony `AmpHttpClient` over a bare
 * `Amp\Http\Client\PooledHttpClient` — the client configurator pins that
 * pool as the whole client. Symfony's own default configurator wraps the
 * pool in an `InterceptedHttpClient` carrying `RetryRequests(2)`, which
 * replays a request underneath Symfony's API where no PSR-18 caller and
 * no Symfony option can see it happen; a configurator supplied to
 * `AmpHttpClientFactory::create()` can equally install
 * `FollowRedirects`, and AMPHP keeps `Authorization` across a redirect
 * whose authority matches — an `https` → `http` hop on the same host
 * included. The pinned configurator is what keeps both out of the chain
 * that a signed `Authorization` and `X-Amz-Security-Token` travel down.
 *
 * ## No redirect
 *
 * `max_redirects => 0` is written into every request this transport
 * forwards, not merely into the delegate's default options: a per-request
 * option overrides a default, and Symfony's PSR-18 adapter builds the
 * option array for the signed request. A 3xx is returned as the
 * response, so a `Location` cannot carry a credential off the configured
 * origin.
 *
 * ## What is configurable
 *
 * Default options — a timeout, headers an endpoint always needs — reach
 * create(). A client does not: this class has no constructor a caller
 * can reach, so a decorator such as Symfony's `RetryableHttpClient` or
 * `ScopingHttpClient` has no way underneath the signature. Put one above
 * `SigV4SigningClient` instead, where a replay costs a fresh signature
 * and is visible as one. `max_redirects` in $defaultOptions is
 * overridden, since request() fixes it.
 *
 * answeredInProcess() is the testing seam: it answers from a function on
 * the calling thread and opens no connection at all. A test asserts on
 * what was sent — the signed headers, the body, the `max_redirects`
 * option this class fixes — by observing that function.
 */
final class SignedTransport implements HttpClientInterface
{
    private function __construct(private readonly HttpClientInterface $delegate) {}

    /**
     * @param array<string, mixed> $defaultOptions applied to every
     *     request made through the returned transport
     */
    public static function create(array $defaultOptions = []): self
    {
        return new self(AmpHttpClientFactory::create(
            [...$defaultOptions, 'max_redirects' => 0],
            static fn (PooledHttpClient $pool): DelegateHttpClient => $pool,
        ));
    }

    /**
     * A transport whose answers come from $responder rather than from a
     * network, for testing code built on `SigV4SigningClient`.
     *
     * $responder is handed the method, the URL, and the fully merged
     * option array of each request, and returns the response to answer
     * it with — the shape `MockHttpClient` takes, which is what backs
     * this.
     *
     * @param callable(string, string, array<string, mixed>): ResponseInterface $responder
     */
    public static function answeredInProcess(callable $responder): self
    {
        return new self(new MockHttpClient($responder));
    }

    /**
     * @param array<string, mixed> $options
     */
    #[\Override]
    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        $options['max_redirects'] = 0;

        return $this->delegate->request($method, $url, $options);
    }

    /**
     * @param ResponseInterface|iterable<array-key, ResponseInterface> $responses
     */
    #[\Override]
    public function stream(ResponseInterface|iterable $responses, ?float $timeout = null): ResponseStreamInterface
    {
        return $this->delegate->stream($responses, $timeout);
    }

    /**
     * @param array<string, mixed> $options
     */
    #[\Override]
    public function withOptions(array $options): static
    {
        return new self($this->delegate->withOptions($options));
    }
}
