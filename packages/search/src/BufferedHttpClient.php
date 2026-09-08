<?php

declare(strict_types=1);

namespace Kinetis\Search;

use Kinetis\Search\Exception\SearchNetworkException;
use Nyholm\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The PSR-18 client an engine transport sends through, over a Symfony
 * HttpClientInterface. One call is one wire attempt: the transport this
 * is given installs no retry interceptor and follows no redirect.
 *
 * The response is complete before sendRequest() returns. Symfony's
 * clients hand back a lazy response whose body is fetched as the caller
 * reads it, and an engine transport reads that body after this method
 * has returned — outside any decorator wrapped around this client, and
 * as whatever exception the PSR-7 stream underneath happens to raise.
 * Buffering the status, headers and body here puts every body-phase
 * transport failure and the response-size limit inside the one call, so
 * a telemetry span still covers them and they arrive as
 * {@see SearchNetworkException}.
 *
 * A status is passed through untouched, so the engine client keeps
 * ownership of every 4xx/5xx mapping, and so are the response headers,
 * which carry Elasticsearch's X-Elastic-Product check and the
 * Content-Type its response objects deserialize by. Only a Symfony
 * TransportExceptionInterface is caught, and an engine's own
 * server-response exceptions are neither wrapped nor sanitized.
 */
final readonly class BufferedHttpClient implements ClientInterface
{
    public function __construct(private HttpClientInterface $client)
    {
    }

    #[\Override]
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        try {
            $response = $this->client->request(
                $request->getMethod(),
                (string) $request->getUri(),
                [
                    'headers' => self::identityEncoded($request->getHeaders()),
                    'body' => (string) $request->getBody(),
                ],
            );

            $status = $response->getStatusCode();
            $headers = $response->getHeaders(false);
            $body = $response->getContent(false);
        } catch (TransportExceptionInterface $e) {
            throw new SearchNetworkException($request, $e);
        }

        return new Response($status, $headers, $body);
    }

    /**
     * Identity is the only accepted content coding, and this is the one
     * place that decides it: the response bound counts bytes off the
     * wire, and a compressed body would let a far larger decoded one
     * through it. Elasticsearch's own ClientBuilder asks for gzip on an
     * Elastic Cloud host unless it recognizes the client class as
     * Symfony's, which this one is not, so whatever a request arrives
     * with is dropped rather than merged with.
     *
     * @param array<string, array<string>> $headers
     * @return array<string, string|array<string>>
     */
    private static function identityEncoded(array $headers): array
    {
        foreach (array_keys($headers) as $name) {
            if (strcasecmp($name, 'Accept-Encoding') === 0) {
                unset($headers[$name]);
            }
        }

        return [...$headers, 'Accept-Encoding' => 'identity'];
    }
}
