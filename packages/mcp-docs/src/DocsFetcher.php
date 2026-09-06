<?php

declare(strict_types=1);

namespace Kinetis\McpDocs;

use Kinetis\McpDocs\Exception\DocsFetchException;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Reads one documentation page's markdown from its source URL over
 * HTTPS. The URL is DocsPage's own, built from two constants — there is
 * no origin, ref or path to configure, and no local copy to prefer, so
 * a read always returns what the monorepo's `main` branch carries at
 * that moment.
 *
 * The client is a constructor argument so the suite can hand over a
 * Symfony MockHttpClient; nothing about the request is settable, and
 * the four properties this server needs are set on the default client
 * and repeated as request options, so they bind whichever client is in
 * use:
 *
 *   - TLS is verified, peer and host, at TLS 1.2 or better.
 *   - Redirects are never followed. The source URLs are fixed and
 *     answer 200; anything else is a failure to report, not a hop to
 *     take.
 *   - An idle timeout and a total deadline both bound the request, so a
 *     server that trickles bytes forever cannot hold a `resources/read`
 *     open indefinitely.
 *   - The body is streamed and abandoned as soon as it passes the size
 *     cap, so a response far larger than any documentation page is
 *     never accumulated whole.
 */
final readonly class DocsFetcher
{
    private const int IDLE_TIMEOUT_SECONDS = 10;
    private const int TOTAL_DEADLINE_SECONDS = 30;
    private const int MAX_BODY_BYTES = 4 * 1024 * 1024;

    private HttpClientInterface $client;

    public function __construct(?HttpClientInterface $client = null)
    {
        $this->client = $client ?? HttpClient::create([
            'verify_peer' => true,
            'verify_host' => true,
            'crypto_method' => STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT,
            'max_redirects' => 0,
            'timeout' => self::IDLE_TIMEOUT_SECONDS,
            'max_duration' => self::TOTAL_DEADLINE_SECONDS,
            'headers' => ['Accept' => 'text/plain, text/markdown'],
        ]);
    }

    /**
     * @throws DocsFetchException
     */
    public function fetch(DocsPage $page): string
    {
        $url = $page->sourceUrl();

        // Only Symfony's own failures are translated here. A
        // DocsFetchException raised inside the block is this class's own
        // verdict and implements none of Symfony's exception interfaces,
        // so it passes straight through rather than being re-wrapped
        // with a second message.
        try {
            $response = $this->client->request('GET', $url, [
                'max_redirects' => 0,
                'timeout' => self::IDLE_TIMEOUT_SECONDS,
                'max_duration' => self::TOTAL_DEADLINE_SECONDS,
            ]);

            $status = $response->getStatusCode();

            if ($status !== 200) {
                $response->cancel();

                throw DocsFetchException::unexpectedStatus($url, $status);
            }

            $body = '';

            foreach ($this->client->stream($response) as $chunk) {
                $body .= $chunk->getContent();

                if (strlen($body) > self::MAX_BODY_BYTES) {
                    $response->cancel();

                    throw DocsFetchException::tooLarge($url, self::MAX_BODY_BYTES);
                }
            }

            return $body;
        } catch (ExceptionInterface $e) {
            throw DocsFetchException::transportFailed($url, $e->getMessage());
        }
    }
}
