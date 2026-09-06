<?php

declare(strict_types=1);

namespace Kinetis\SimpleCache\Tests;

use Kinetis\Config\Config;
use Kinetis\Config\Exception\MissingConfigException;
use Kinetis\Redis\Client;
use Kinetis\Redis\ClusterClient;
use Kinetis\SimpleCache\Exception\InvalidArgumentException;
use Kinetis\SimpleCache\RedisConnectionFactory;
use PHPUnit\Framework\TestCase;

/**
 * Configuration mapping only. Both clients open their sockets on the
 * first command, so every case here runs with no Redis server reachable.
 */
final class RedisConnectionFactoryTest extends TestCase
{
    public function test_returns_null_when_redis_is_not_configured(): void
    {
        self::assertNull(RedisConnectionFactory::fromConfig(new Config([])));
    }

    public function test_a_host_builds_a_single_node_client(): void
    {
        $client = RedisConnectionFactory::fromConfig(new Config([
            'REDIS_HOST' => 'cache.internal',
            'REDIS_PORT' => '7000',
        ]));

        self::assertInstanceOf(Client::class, $client);
        self::assertSame('cache.internal', $client->endpoint->host);
        self::assertSame(7000, $client->endpoint->port);
    }

    public function test_a_host_without_a_port_uses_the_redis_default(): void
    {
        $client = RedisConnectionFactory::fromConfig(new Config(['REDIS_HOST' => 'cache.internal']));

        self::assertInstanceOf(Client::class, $client);
        self::assertSame(6379, $client->endpoint->port);
    }

    public function test_a_url_carries_the_endpoint_and_wins_over_the_discrete_keys(): void
    {
        $client = RedisConnectionFactory::fromConfig(new Config([
            'REDIS_URL' => 'redis://:secret@cache.internal:7000/3',
            'REDIS_HOST' => 'ignored.internal',
        ]));

        self::assertInstanceOf(Client::class, $client);
        self::assertSame('cache.internal', $client->endpoint->host);
        self::assertSame(7000, $client->endpoint->port);
    }

    public function test_cluster_seeds_build_a_cluster_client(): void
    {
        $client = RedisConnectionFactory::fromConfig(new Config([
            'REDIS_CLUSTER' => 'true',
            'REDIS_CLUSTER_SEEDS' => 'node1:7001, [2001:db8::10]:7002',
        ]));

        self::assertInstanceOf(ClusterClient::class, $client);
    }

    public function test_cluster_mode_without_seeds_is_reported_as_missing_configuration(): void
    {
        $this->expectException(MissingConfigException::class);

        RedisConnectionFactory::fromConfig(new Config(['REDIS_CLUSTER' => 'true']));
    }

    public function test_a_malformed_cluster_seed_is_refused_before_any_client_exists(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        RedisConnectionFactory::fromConfig(new Config([
            'REDIS_CLUSTER' => 'true',
            'REDIS_CLUSTER_SEEDS' => 'node1',
        ]));
    }

    public function test_a_named_connection_reads_its_own_keys_and_ignores_the_default_ones(): void
    {
        $config = new Config([
            'REDIS_HOST' => 'default.internal',
            'REDIS_CACHE2_HOST' => 'cache2.internal',
            'REDIS_CACHE2_PORT' => '7001',
        ]);

        $named = RedisConnectionFactory::fromConfig($config, 'cache2');

        self::assertInstanceOf(Client::class, $named);
        self::assertSame('cache2.internal', $named->endpoint->host);
        self::assertSame(7001, $named->endpoint->port);
        self::assertNull(RedisConnectionFactory::fromConfig(new Config(['REDIS_HOST' => 'default.internal']), 'cache2'));
    }

    public function test_the_timeout_is_the_whole_operation_budget(): void
    {
        $options = RedisConnectionFactory::options(new Config(['REDIS_TIMEOUT' => '2.5']));

        self::assertSame(2.5, $options->timeout);
    }

    public function test_a_non_positive_timeout_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('REDIS_TIMEOUT must be a positive number of seconds');

        RedisConnectionFactory::options(new Config(['REDIS_TIMEOUT' => '0']));
    }

    public function test_tls_is_off_unless_asked_for_and_verifies_the_peer_when_on(): void
    {
        self::assertNull(RedisConnectionFactory::options(new Config([]))->tls);

        $tls = RedisConnectionFactory::options(new Config(['REDIS_TLS' => 'true']))->tls;

        self::assertNotNull($tls);
        self::assertTrue($tls->hasPeerVerification());
    }

    public function test_peer_verification_is_only_dropped_when_configuration_says_so(): void
    {
        $tls = RedisConnectionFactory::options(new Config([
            'REDIS_TLS' => 'true',
            'REDIS_TLS_VERIFY_PEER' => 'false',
        ]))->tls;

        self::assertNotNull($tls);
        self::assertFalse($tls->hasPeerVerification());
    }

    public function test_a_ca_file_is_carried_into_the_tls_context(): void
    {
        $tls = RedisConnectionFactory::options(new Config([
            'REDIS_TLS' => 'true',
            'REDIS_TLS_CA_FILE' => '/etc/ssl/redis-ca.pem',
        ]))->tls;

        self::assertNotNull($tls);
        self::assertSame('/etc/ssl/redis-ca.pem', $tls->getCaFile());
    }
}
