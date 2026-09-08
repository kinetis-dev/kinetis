<?php

declare(strict_types=1);

namespace Kinetis\SimpleCache;

use Amp\Socket\ClientTlsContext;
use Kinetis\Config\Config;
use Kinetis\Redis\Client;
use Kinetis\Redis\ClientOptions;
use Kinetis\Redis\ClusterClient;
use Kinetis\Redis\ConnectionUri;
use Kinetis\Redis\Endpoint;
use Kinetis\Redis\RoutedExecutor;
use Kinetis\SimpleCache\Exception\InvalidArgumentException;

/**
 * Turns this package's `REDIS_*` configuration into a
 * `Kinetis\Redis\RoutedExecutor`.
 *
 * Every key is scoped by connection name, following the named-connection
 * convention in {doc}`config`. Nothing here connects: both clients open
 * their sockets on the first command.
 */
final class RedisConnectionFactory
{
    public static function fromConfig(Config $config, string $connection = 'default'): ?RoutedExecutor
    {
        $options = self::options($config, $connection);

        if ($config->bool(Config::scopedKey('REDIS_CLUSTER', $connection), false)) {
            $seeds = array_map(
                Endpoint::parse(...),
                array_map(trim(...), explode(',', $config->required(Config::scopedKey('REDIS_CLUSTER_SEEDS', $connection)))),
            );

            return ClusterClient::create($seeds, $options);
        }

        $url = $config->string(Config::scopedKey('REDIS_URL', $connection), '');

        if ($url !== '') {
            $parsed = ConnectionUri::parse($url);

            return Client::create(
                $parsed->endpoint,
                $options->withPassword($parsed->password ?? $options->password)->withDatabase($parsed->database),
            );
        }

        $host = $config->string(Config::scopedKey('REDIS_HOST', $connection), '');

        if ($host === '') {
            return null;
        }

        $portKey = Config::scopedKey('REDIS_PORT', $connection);
        $endpoint = Endpoint::fromParts($host, $config->int($portKey, 6379));

        $databaseKey = Config::scopedKey('REDIS_DATABASE', $connection);

        return Client::create($endpoint, $options->withDatabase($config->int($databaseKey, 0)));
    }

    /**
     * The password, TLS context and operation budget every node of this
     * connection shares. `REDIS_TIMEOUT` is the whole per-operation
     * budget, not a connect timeout.
     */
    public static function options(Config $config, string $connection = 'default'): ClientOptions
    {
        $timeoutKey = Config::scopedKey('REDIS_TIMEOUT', $connection);
        $timeout = $config->float($timeoutKey, 5.0);

        if ($timeout <= 0.0) {
            throw new InvalidArgumentException("{$timeoutKey} must be a positive number of seconds, got {$timeout}.");
        }

        $password = $config->get(Config::scopedKey('REDIS_PASSWORD', $connection));

        return new ClientOptions($timeout, $password, tls: self::tls($config, $connection));
    }

    /**
     * Discovered and redirected cluster nodes are reached at the address
     * the cluster announces, so their certificates must carry a matching
     * SAN. Verification is never relaxed for them; set
     * `REDIS_TLS_VERIFY_PEER=false` to turn it off for the whole
     * connection or announce hostnames on the server.
     */
    private static function tls(Config $config, string $connection): ?ClientTlsContext
    {
        if (!$config->bool(Config::scopedKey('REDIS_TLS', $connection), false)) {
            return null;
        }

        $context = new ClientTlsContext('');

        if (!$config->bool(Config::scopedKey('REDIS_TLS_VERIFY_PEER', $connection), true)) {
            $context = $context->withoutPeerVerification();
        }

        $caFile = $config->get(Config::scopedKey('REDIS_TLS_CA_FILE', $connection));

        return $caFile !== null ? $context->withCaFile($caFile) : $context;
    }
}
