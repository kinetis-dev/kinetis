<?php

declare(strict_types=1);

namespace Kinetis\SearchOpenSearch;

use Closure;
use Kinetis\Config\Config;
use Kinetis\Search\Exception\SearchConfigurationException;
use Kinetis\Search\SearchTransport;
use OpenSearch\Client;
use OpenSearch\EndpointFactory;
use OpenSearch\TransportFactory;
use Psr\Http\Client\ClientInterface;

/**
 * Builds an OpenSearch\Client through OpenSearch's own TransportFactory/
 * HttpTransport construction path. TransportFactory::setHttpClient()
 * takes a PSR-18 ClientInterface, and {@see SearchTransport} supplies one
 * over the Revolt-backed Symfony client, so a search suspends the calling
 * Fiber instead of blocking the worker. The endpoint factory, serializer,
 * request building and response mapping all stay the official client's.
 *
 * The origin, deadline, response bound, credentials and TLS decision come
 * from SEARCH_OPENSEARCH_* through {@see SearchTransport}; see it for what
 * each key means and what a host may be.
 *
 * $connection selects a named connection via Config::scopedKey(), the
 * convention every other *Factory::fromConfig() in this project follows.
 */
final class OpenSearchClientFactory
{
    /** The configuration prefix {@see SearchTransport} reads every key under. */
    public const string CONFIG_PREFIX = 'SEARCH_OPENSEARCH';

    /**
     * $transportDecorator wraps the fully-configured PSR-18 client
     * (origin policy, deadline, response bound, auth and TLS already
     * applied) right before it is handed to TransportFactory — the seam
     * kinetis/telemetry's TracingSearchTransport uses.
     *
     * Construction reaches no network: an unreachable cluster is not a
     * configuration error and surfaces on the first search.
     *
     * @param ?Closure(ClientInterface): ClientInterface $transportDecorator
     *
     * @throws SearchConfigurationException
     */
    public static function fromConfig(Config $config, string $connection = 'default', ?Closure $transportDecorator = null): Client
    {
        $transport = (new TransportFactory())
            ->setHttpClient(SearchTransport::fromConfig($config, self::CONFIG_PREFIX, $connection, $transportDecorator)->client)
            ->create();

        return new Client($transport, new EndpointFactory());
    }
}
