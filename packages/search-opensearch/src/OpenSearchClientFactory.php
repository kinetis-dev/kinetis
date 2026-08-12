<?php

declare(strict_types=1);

namespace Kinetis\SearchOpenSearch;

use Kinetis\Config\Config;
use Kinetis\RevoltHttpClient\AmpHttpClientFactory;
use OpenSearch\Client;
use OpenSearch\EndpointFactory;
use OpenSearch\TransportFactory;
use Symfony\Component\HttpClient\Psr18Client;

/**
 * Builds an OpenSearch\Client through OpenSearch's own non-deprecated
 * TransportFactory/HttpTransport construction path — the older
 * ClientBuilder/Transport/ConnectionPool stack (deprecated since 2.4.0)
 * only ever builds a blocking cURL-based client and has no PSR-18
 * injection point at all. TransportFactory::setHttpClient() takes a real
 * PSR-18 ClientInterface directly; Symfony\Component\HttpClient\Psr18Client
 * is the standard adapter turning AmpHttpClientFactory::create()'s
 * Revolt-backed Symfony HttpClientInterface into that PSR-18 shape —
 * still genuinely Fiber-suspending underneath, the same mechanism already
 * proven for kinetis/storage-s3's S3Client and kinetis/queue-sqs's
 * SqsClient.
 *
 * The host is a single base URI, not a list — TransportFactory/
 * HttpTransport has no multi-node selector/failover concept the way the
 * deprecated ClientBuilder::setHosts() did; a consumer wanting client-side
 * failover across multiple nodes needs a load balancer in front of the
 * cluster instead.
 */
final class OpenSearchClientFactory
{
    public static function fromConfig(Config $config, string $connection = 'default'): Client
    {
        $host = $config->required(Config::scopedKey('SEARCH_OPENSEARCH_HOST', $connection));
        $username = $config->string(Config::scopedKey('SEARCH_OPENSEARCH_USERNAME', $connection), '');
        $password = $config->string(Config::scopedKey('SEARCH_OPENSEARCH_PASSWORD', $connection), '');

        $defaultOptions = [
            'base_uri' => $host,
            // OpenSearch's own request-building code never sets its own
            // Content-Type — it relies on the HTTP client defaulting to
            // JSON for a string body. Symfony's HttpClient defaults an
            // unmarked string body to application/x-www-form-urlencoded
            // instead, which OpenSearch's server rejects outright (406);
            // confirmed by hitting that exact error against a real
            // container, not assumed from reading the client's source
            // alone.
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
        ];

        if ($username !== '') {
            $defaultOptions['auth_basic'] = [$username, $password];
        }

        // OpenSearch's own security plugin ships enabled by default with a
        // self-signed demo certificate — verified directly against a real
        // security-enabled container, not assumed: an unauthenticated
        // request is rejected (401), and a correctly Basic-authenticated
        // one succeeds only once peer verification is turned off for that
        // self-signed cert. Defaults to true (secure by default), matching
        // REDIS_TLS_VERIFY_PEER's own convention in kinetis/cache-redis.
        if (!$config->bool(Config::scopedKey('SEARCH_OPENSEARCH_VERIFY_PEER', $connection), true)) {
            $defaultOptions['verify_peer'] = false;
            $defaultOptions['verify_host'] = false;
        }

        $httpClient = new Psr18Client(AmpHttpClientFactory::create($defaultOptions));

        $transport = (new TransportFactory())
            ->setHttpClient($httpClient)
            ->create();

        return new Client($transport, new EndpointFactory());
    }
}
