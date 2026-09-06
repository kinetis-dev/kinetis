<?php

declare(strict_types=1);

namespace Kinetis\Mailer;

use Kinetis\Mailer\Exception\InsecureRequestException;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

/**
 * The HTTP client every API transport is given: one that will only make
 * an absolute HTTPS request carrying no userinfo, with the certificate
 * verified and no redirect followed.
 *
 * A mail POST carries the provider authorization header, the envelope,
 * every recipient address, the message headers and body, and any
 * attachment bytes. Four things are therefore decided here rather than
 * left to a default or to whatever a bridge passes per call:
 *
 * - **The target is absolute HTTPS.** A `http://` endpoint, a
 *   protocol-relative `//host/path`, a path resolved against a
 *   `base_uri`, and anything `parse_url()` cannot read are all refused
 *   before the inner client sees them. A bridge builds its own endpoint
 *   from a DSN host, so this is the last place that endpoint can be
 *   checked.
 * - **No credential rides in the URL.** A `user:pass@` in the target is
 *   refused too. Every bridge in the registry sends its credential in a
 *   header or a signed request, and a URL is what reaches an access log,
 *   a proxy and a trace, so a target carrying userinfo is one that has
 *   already put a secret where it does not belong.
 * - **The certificate is verified.** `verify_peer` and `verify_host` are
 *   set to `true` over anything the caller or a factory default supplied.
 * - **No redirect is followed.** `max_redirects` is `0`, not Symfony's
 *   default of `20`, so a `Location` cannot replay the whole request
 *   against whatever host a response named.
 *
 * Every invariant is written on the way through rather than merged
 * under, and `withOptions()` applies the same writes and returns another
 * decorator, so a bridge deriving a configured client from this one
 * cannot derive its way out.
 *
 * A 3xx response is returned to the bridge as the response it is.
 * Symfony raises a `RedirectionExceptionInterface` from `getContent()`
 * on one, which is the ordinary failure path a transport already
 * handles.
 *
 * **What the client promises about exceptions, and what it does not.**
 * Its own refusal is {@see InsecureRequestException}: one fixed message,
 * no cause, and every parameter of every method here — the wrapped
 * client, the URL, the options, the responses handed to `stream()` — is
 * marked `#[\SensitiveParameter]`, so the frames this class contributes
 * to any trace render as `SensitiveParameterValue` rather than as the
 * token, address list or MIME body they hold. Failures raised by the
 * inner client are not rewritten: Symfony's contract has a bridge read
 * status, headers and body off the response and catch its
 * `TransportExceptionInterface`, `HttpExceptionInterface` and
 * `RedirectionExceptionInterface` by type, and an exception replaced here
 * would take those, and the response they carry, away from the bridge
 * that handles them. A frame above this class in such a trace — a
 * bridge's `doSendHttp()` holding the message it was sending — is that
 * class's own. The boundary that makes a *send* secret-free is
 * {@see SafeMailer}, which replaces whatever the transport threw with
 * {@see \Kinetis\Mailer\Exception\MailSendFailedException}; this client
 * enforces the request policy and keeps its own frames clean, and
 * promises nothing further about a failure it did not raise.
 */
final readonly class NoRedirectHttpClient implements HttpClientInterface
{
    public function __construct(
        #[\SensitiveParameter]
        private HttpClientInterface $client,
    ) {}

    #[\Override]
    public function request(
        string $method,
        #[\SensitiveParameter] string $url,
        #[\SensitiveParameter] array $options = [],
    ): ResponseInterface {
        if (!self::isAbsoluteHttpsWithoutUserinfo($url)) {
            throw InsecureRequestException::notAbsoluteHttps();
        }

        return $this->client->request($method, $url, self::enforced($options));
    }

    #[\Override]
    public function stream(
        #[\SensitiveParameter] ResponseInterface|iterable $responses,
        ?float $timeout = null,
    ): ResponseStreamInterface {
        return $this->client->stream($responses, $timeout);
    }

    #[\Override]
    public function withOptions(#[\SensitiveParameter] array $options): static
    {
        return new self($this->client->withOptions(self::enforced($options)));
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private static function enforced(#[\SensitiveParameter] array $options): array
    {
        $options['max_redirects'] = 0;
        $options['verify_peer'] = true;
        $options['verify_host'] = true;

        return $options;
    }

    private static function isAbsoluteHttpsWithoutUserinfo(#[\SensitiveParameter] string $url): bool
    {
        $parts = parse_url($url);

        if (!is_array($parts)) {
            return false;
        }

        // `array_key_exists` rather than `isset`: `https://:token@host`
        // parses to an empty user and a set password, and an empty user
        // half is still userinfo.
        return strtolower($parts['scheme'] ?? '') === 'https'
            && ($parts['host'] ?? '') !== ''
            && !array_key_exists('user', $parts)
            && !array_key_exists('pass', $parts);
    }
}
