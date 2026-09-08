<?php

declare(strict_types=1);

namespace Kinetis\Search;

use Closure;
use Kinetis\Config\Config;
use Kinetis\RevoltHttpClient\AmpHttpClientFactory;
use Kinetis\Search\Exception\SearchConfigurationException;
use Kinetis\Search\Exception\SearchResponseTooLargeException;
use Psr\Http\Client\ClientInterface;

/**
 * The one origin an engine client talks to, and the PSR-18 client it
 * talks over: a {@see BufferedHttpClient} on the Revolt-backed Symfony
 * client AmpHttpClientFactory::create() returns, so a search suspends
 * the calling Fiber instead of blocking the worker.
 *
 * Both engine packages read the same policy from here under their own
 * configuration prefix — SEARCH_OPENSEARCH or SEARCH_ELASTICSEARCH —
 * so an origin, a deadline, a response bound, a credential and a TLS
 * decision mean the same thing whichever engine an application runs.
 * Everything above this — endpoint building, serialization, status
 * mapping — stays the engine client's own.
 *
 * A host is one root origin and one node: `scheme://host[:port]` and
 * nothing under it. One root origin is this class's own configuration
 * contract, so a host means the same thing under either engine's
 * prefix; put a load balancer in front of a multi-node cluster and
 * point this at it.
 *
 * $connection selects a named connection via Config::scopedKey(), the
 * convention every other *::fromConfig() in this project follows.
 */
final readonly class SearchTransport
{
    /**
     * Seconds. Both the idle timeout between bytes and the total duration
     * of one request, so a response fed a byte at a time cannot outlive
     * it. It bounds one HTTP call, not a sequence of them.
     */
    private const float DEFAULT_TIMEOUT = 30.0;

    /**
     * Bytes. A search that matches more than expected, or an index whose
     * documents are larger than expected, answers with a body this
     * package would otherwise buffer whole into the worker.
     */
    private const int DEFAULT_MAX_RESPONSE_BYTES = 8_388_608;

    private function __construct(
        /** `scheme://host[:port]`, lowercased and rebuilt from the parts a host may carry. */
        public string $origin,
        public ClientInterface $client,
    ) {
    }

    /**
     * $prefix is the engine's configuration prefix without a trailing
     * underscore: `SEARCH_OPENSEARCH` reads SEARCH_OPENSEARCH_HOST,
     * SEARCH_OPENSEARCH_TIMEOUT, and the rest.
     *
     * $decorator wraps the fully-configured PSR-18 client — origin
     * policy, deadline, response bound, auth and TLS already applied —
     * right before an engine's own transport is built around it. It is
     * the seam kinetis/telemetry's TracingSearchTransport uses, so a
     * decorator never has to duplicate this class's config reading. It
     * wraps a client that has already buffered the response, so a
     * decorator's own call covers the whole exchange.
     *
     * Construction reaches no network: an unreachable cluster is not a
     * configuration error and surfaces on the first search.
     *
     * @param ?Closure(ClientInterface): ClientInterface $decorator
     *
     * @throws SearchConfigurationException
     */
    public static function fromConfig(
        Config $config,
        string $prefix,
        string $connection = 'default',
        ?Closure $decorator = null,
    ): self {
        $origin = self::origin($config, $prefix, $connection);

        $client = new BufferedHttpClient(
            AmpHttpClientFactory::create(self::transportOptions($config, $prefix, $connection, $origin)),
        );

        return new self($origin, $decorator === null ? $client : $decorator($client));
    }

    /**
     * One request is one wire attempt against the one configured origin:
     * no redirect is followed, so a response that points elsewhere cannot
     * carry the Basic credentials of this request to another host, and no
     * retry is installed underneath. An engine transport that installs
     * its own retry is the engine package's to disable.
     *
     * @return array<string, mixed>
     */
    private static function transportOptions(Config $config, string $prefix, string $connection, string $origin): array
    {
        $timeoutKey = Config::scopedKey($prefix . '_TIMEOUT', $connection);
        $timeout = $config->float($timeoutKey, self::DEFAULT_TIMEOUT);

        if ($timeout <= 0.0) {
            throw SearchConfigurationException::nonPositiveTimeout($timeoutKey);
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
            // which a node answers with 406. Elasticsearch's endpoints
            // set their own, including application/x-ndjson for bulk,
            // and a request's own header wins over this default.
            //
            // So an OpenSearch _bulk body travels under this JSON
            // default rather than under x-ndjson, which is what that
            // engine's bulk handler accepts and what the package's
            // real-cluster checks exercise.
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
            'on_progress' => self::responseBound($config, $prefix, $connection),
        ];

        $username = $config->string(Config::scopedKey($prefix . '_USERNAME', $connection), '');

        if ($username !== '') {
            $options['auth_basic'] = [
                $username,
                $config->string(Config::scopedKey($prefix . '_PASSWORD', $connection), ''),
            ];
        }

        // Both engines ship security enabled with a self-signed demo
        // certificate, which peer verification rejects. Verification
        // stays on by default, matching REDIS_TLS_VERIFY_PEER.
        if (!$config->bool(Config::scopedKey($prefix . '_VERIFY_PEER', $connection), true)) {
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
     * exception, which {@see BufferedHttpClient} turns into a
     * SearchNetworkException carrying the request; the
     * {@see SearchResponseTooLargeException} stays underneath, naming the
     * key that ended the transfer.
     */
    private static function responseBound(Config $config, string $prefix, string $connection): Closure
    {
        $limitKey = Config::scopedKey($prefix . '_MAX_RESPONSE_BYTES', $connection);
        $limit = $config->int($limitKey, self::DEFAULT_MAX_RESPONSE_BYTES);

        if ($limit <= 0) {
            throw SearchConfigurationException::nonPositiveResponseLimit($limitKey);
        }

        return static function (int $downloaded) use ($limit, $limitKey): void {
            if ($downloaded > $limit) {
                throw new SearchResponseTooLargeException($limitKey);
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
    private static function origin(Config $config, string $prefix, string $connection): string
    {
        $key = Config::scopedKey($prefix . '_HOST', $connection);
        $parts = parse_url($config->required($key));

        if ($parts === false) {
            throw SearchConfigurationException::malformedHost($key, 'it does not parse as a URL, or its port is out of range');
        }

        if (!isset($parts['scheme'], $parts['host']) || $parts['host'] === '') {
            throw SearchConfigurationException::malformedHost($key, 'it needs a scheme and a host');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw SearchConfigurationException::malformedHost($key, 'it carries userinfo');
        }

        if (isset($parts['query']) || isset($parts['fragment'])) {
            throw SearchConfigurationException::malformedHost($key, 'it carries a query or fragment');
        }

        if (($parts['path'] ?? '/') !== '/') {
            throw SearchConfigurationException::malformedHost($key, 'it carries a path');
        }

        $scheme = strtolower($parts['scheme']);

        if ($scheme === 'http') {
            $plaintextKey = Config::scopedKey($prefix . '_PLAINTEXT', $connection);

            if (!$config->bool($plaintextKey, false)) {
                throw SearchConfigurationException::plaintextHost($key, $plaintextKey);
            }
        } elseif ($scheme !== 'https') {
            throw SearchConfigurationException::malformedHost($key, 'only http and https are supported');
        }

        return $scheme . '://' . strtolower($parts['host']) . (isset($parts['port']) ? ':' . $parts['port'] : '');
    }
}
