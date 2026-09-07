<?php

declare(strict_types=1);

namespace Kinetis\SearchOpenSearch;

use Closure;
use Kinetis\Config\Config;
use Kinetis\RevoltHttpClient\AmpHttpClientFactory;
use Kinetis\SearchOpenSearch\Exception\OpenSearchConfigurationException;
use OpenSearch\Client;
use OpenSearch\EndpointFactory;
use OpenSearch\TransportFactory;
use Psr\Http\Client\ClientInterface;
use RuntimeException;

/**
 * Builds an OpenSearch\Client through OpenSearch's own TransportFactory/
 * HttpTransport construction path. TransportFactory::setHttpClient()
 * takes a PSR-18 ClientInterface, and {@see OpenSearchHttpClient} is
 * this package's own, over the Revolt-backed Symfony client
 * AmpHttpClientFactory::create() returns, so a search suspends the
 * calling Fiber instead of blocking the worker. The endpoint factory,
 * serializer, request building and response mapping all stay the
 * official client's.
 *
 * SEARCH_OPENSEARCH_HOST is one origin and one node. This construction
 * path has no multi-node selector or failover, and the endpoints the
 * official client builds are root-relative, so a base path under the
 * origin would be dropped rather than honoured; put a load balancer in
 * front of a multi-node cluster and point this at it.
 *
 * $connection selects a named connection via Config::scopedKey(), the
 * convention every other *Factory::fromConfig() in this project follows.
 */
final class OpenSearchClientFactory
{
    /**
     * Seconds. Both the idle timeout between bytes and the total duration
     * of one request, so a response fed a byte at a time cannot outlive
     * it. It bounds one HTTP call, not a sequence of them.
     */
    private const DEFAULT_TIMEOUT = 30.0;

    /**
     * Bytes. A search that matches more than expected, or an index whose
     * documents are larger than expected, answers with a body this
     * package would otherwise buffer whole into the worker.
     */
    private const DEFAULT_MAX_RESPONSE_BYTES = 8_388_608;

    /**
     * $transportDecorator wraps the fully-configured PSR-18 client
     * (origin policy, deadline, response bound, auth and TLS already
     * applied) right before it is handed to TransportFactory — the seam
     * kinetis/telemetry's TracingOpenSearchTransport uses, so a decorator
     * never has to duplicate this method's own config-reading logic. It
     * wraps {@see OpenSearchHttpClient}, so the response is already
     * buffered by the time the decorator's own call returns.
     *
     * Construction reaches no network: an unreachable cluster is not a
     * configuration error and surfaces on the first search.
     *
     * @param ?Closure(ClientInterface): ClientInterface $transportDecorator
     *
     * @throws OpenSearchConfigurationException
     */
    public static function fromConfig(Config $config, string $connection = 'default', ?Closure $transportDecorator = null): Client
    {
        $httpClient = new OpenSearchHttpClient(
            AmpHttpClientFactory::create(self::transportOptions($config, $connection)),
        );

        if ($transportDecorator !== null) {
            $httpClient = $transportDecorator($httpClient);
        }

        $transport = (new TransportFactory())
            ->setHttpClient($httpClient)
            ->create();

        return new Client($transport, new EndpointFactory());
    }

    /**
     * One request is one wire attempt against the one configured origin:
     * no redirect is followed, so a response that points elsewhere cannot
     * carry the Basic credentials of this request to another host, and no
     * retry is installed underneath.
     *
     * Identity is the only accepted content coding. The response bound
     * below counts bytes off the wire, and a compressed body would let a
     * far larger decoded one through it.
     *
     * @return array<string, mixed>
     */
    private static function transportOptions(Config $config, string $connection): array
    {
        $origin = self::origin($config, $connection);

        $timeoutKey = Config::scopedKey('SEARCH_OPENSEARCH_TIMEOUT', $connection);
        $timeout = $config->float($timeoutKey, self::DEFAULT_TIMEOUT);

        if ($timeout <= 0.0) {
            throw OpenSearchConfigurationException::nonPositiveTimeout($timeoutKey);
        }

        $options = [
            'base_uri' => $origin,
            'timeout' => $timeout,
            'max_duration' => $timeout,
            'max_redirects' => 0,
            // OpenSearch's own request building never sets a
            // Content-Type; it relies on the HTTP client defaulting to
            // JSON for a string body, while Symfony's clients default an
            // unmarked string body to application/x-www-form-urlencoded,
            // which an OpenSearch node answers with 406.
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'Accept-Encoding' => 'identity',
            ],
            'on_progress' => self::responseBound($config, $connection),
        ];

        $username = $config->string(Config::scopedKey('SEARCH_OPENSEARCH_USERNAME', $connection), '');

        if ($username !== '') {
            $options['auth_basic'] = [
                $username,
                $config->string(Config::scopedKey('SEARCH_OPENSEARCH_PASSWORD', $connection), ''),
            ];
        }

        // OpenSearch's security plugin ships enabled with a self-signed
        // demo certificate, which peer verification rejects. Verification
        // stays on by default, matching REDIS_TLS_VERIFY_PEER.
        if (!$config->bool(Config::scopedKey('SEARCH_OPENSEARCH_VERIFY_PEER', $connection), true)) {
            $options['verify_peer'] = false;
            $options['verify_host'] = false;
        }

        return $options;
    }

    /**
     * Symfony calls this with the bytes downloaded so far, the expected
     * total and the transfer info, and treats a throw as an abort. Only
     * the first is needed: a body is refused once it passes the limit,
     * whether or not the node declared a length up front.
     *
     * The throw never surfaces as itself. Symfony wraps it in a transport
     * exception, which {@see OpenSearchHttpClient} turns into an
     * OpenSearchNetworkException carrying the request.
     */
    private static function responseBound(Config $config, string $connection): Closure
    {
        $limitKey = Config::scopedKey('SEARCH_OPENSEARCH_MAX_RESPONSE_BYTES', $connection);
        $limit = $config->int($limitKey, self::DEFAULT_MAX_RESPONSE_BYTES);

        if ($limit <= 0) {
            throw OpenSearchConfigurationException::nonPositiveResponseLimit($limitKey);
        }

        return static function (int $downloaded) use ($limit, $limitKey): void {
            if ($downloaded > $limit) {
                throw new RuntimeException("The OpenSearch response passed {$limitKey}.");
            }
        };
    }

    /**
     * A host is an origin and nothing else: a path for the reason the
     * class docblock gives, userinfo because it is a credential lower
     * transport errors can quote, a query or fragment because neither has
     * anywhere to go. Rebuilding the accepted parts rather than passing
     * the string through keeps whatever else parse_url() tolerated out of
     * the URL, and leaves a bracketed IPv6 host in the brackets the URL
     * needs.
     *
     * Plain HTTP is an explicit opt-in rather than an address check:
     * `http://opensearch:9200` between containers on one Compose network
     * is as legitimate as a loopback address, and no parse of the host
     * can tell either from a public one.
     */
    private static function origin(Config $config, string $connection): string
    {
        $key = Config::scopedKey('SEARCH_OPENSEARCH_HOST', $connection);
        $parts = parse_url($config->required($key));

        if ($parts === false) {
            throw OpenSearchConfigurationException::malformedHost($key, 'it does not parse as a URL, or its port is out of range');
        }

        if (!isset($parts['scheme'], $parts['host']) || $parts['host'] === '') {
            throw OpenSearchConfigurationException::malformedHost($key, 'it needs a scheme and a host');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw OpenSearchConfigurationException::malformedHost($key, 'it carries userinfo');
        }

        if (isset($parts['query']) || isset($parts['fragment'])) {
            throw OpenSearchConfigurationException::malformedHost($key, 'it carries a query or fragment');
        }

        if (($parts['path'] ?? '/') !== '/') {
            throw OpenSearchConfigurationException::malformedHost($key, 'it carries a path, which this client cannot honour');
        }

        $scheme = strtolower($parts['scheme']);

        if ($scheme === 'http') {
            $plaintextKey = Config::scopedKey('SEARCH_OPENSEARCH_PLAINTEXT', $connection);

            if (!$config->bool($plaintextKey, false)) {
                throw OpenSearchConfigurationException::plaintextHost($key, $plaintextKey);
            }
        } elseif ($scheme !== 'https') {
            throw OpenSearchConfigurationException::malformedHost($key, 'only http and https are supported');
        }

        return $scheme . '://' . strtolower($parts['host']) . (isset($parts['port']) ? ':' . $parts['port'] : '');
    }
}
