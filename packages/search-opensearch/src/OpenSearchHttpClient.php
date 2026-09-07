<?php

declare(strict_types=1);

namespace Kinetis\SearchOpenSearch;

use Kinetis\SearchOpenSearch\Exception\OpenSearchNetworkException;
use Nyholm\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The PSR-18 client OpenSearch\HttpTransport sends through, over a
 * Symfony HttpClientInterface. One call is one wire attempt: the
 * transport this is given installs no retry interceptor and follows no
 * redirect.
 *
 * The response is complete before sendRequest() returns. Symfony's
 * clients hand back a lazy response whose body is fetched as the caller
 * reads it, and OpenSearch\HttpTransport reads that body after this
 * method has returned — outside any decorator wrapped around this
 * client, and as whatever exception the PSR-7 stream underneath happens
 * to raise. Buffering the status, headers and body here puts every
 * body-phase transport failure and the response-size limit inside the
 * one call, so a telemetry span still covers them and they arrive as
 * {@see OpenSearchNetworkException}.
 *
 * A status is passed through untouched, so the official client keeps
 * ownership of every OpenSearch 4xx/5xx mapping. Only a Symfony
 * TransportExceptionInterface is caught, and the official client's own
 * server-response exceptions are neither wrapped nor sanitized.
 */
final readonly class OpenSearchHttpClient implements ClientInterface
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
                    'headers' => $request->getHeaders(),
                    'body' => (string) $request->getBody(),
                ],
            );

            $status = $response->getStatusCode();
            $headers = $response->getHeaders(false);
            $body = $response->getContent(false);
        } catch (TransportExceptionInterface $e) {
            throw new OpenSearchNetworkException($request, $e);
        }

        return new Response($status, $headers, $body);
    }
}
