<?php

declare(strict_types=1);

namespace Kinetis\SimpleCache\Connection;

use Amp\Cancellation;
use Amp\Redis\Connection\RedisConnection;
use Amp\Redis\Connection\RedisConnectionException;
use Amp\Redis\Connection\RedisConnector;
use Amp\Redis\Connection\SocketRedisConnection;
use Amp\Socket;
use Amp\Socket\ClientTlsContext;
use Amp\Socket\ConnectContext;
use Kinetis\Config\Config;

/**
 * The TLS-capable counterpart to Amp\Redis\Connection\SocketRedisConnector,
 * which only ever performs a plain, unencrypted connect(). Amp\Redis has
 * no built-in way to reach a TLS-only Redis endpoint: establishing TLS
 * requires Amp\Socket\connectTls() specifically, a different function from
 * the plain connect() SocketRedisConnector calls — confirmed directly
 * against both packages' source, not assumed from RedisConfig's URI
 * handling alone.
 *
 * Passed as the $connector override to Amp\Redis\createRedisClient()/
 * createRedisConnector(), which still layers its own password/database-
 * select decorators on top exactly as it would around the default
 * connector — this class only replaces the raw socket step.
 */
final class TlsRedisConnector implements RedisConnector
{
    public function __construct(
        private readonly string $uri,
        private readonly ConnectContext $connectContext,
    ) {}

    /**
     * The one place RedisSimpleCache and ClusteredRedisSimpleCache both
     * decide whether/how to use TLS, so that decision only exists once.
     * Returns null when REDIS_TLS isn't enabled for this connection —
     * the caller falls back to a plain connector.
     */
    public static function fromConfig(Config $config, string $uri, float $timeout, string $connection = 'default'): ?self
    {
        if (!$config->bool(Config::scopedKey('REDIS_TLS', $connection), false)) {
            return null;
        }

        $tlsContext = new ClientTlsContext('');

        if (!$config->bool(Config::scopedKey('REDIS_TLS_VERIFY_PEER', $connection), true)) {
            $tlsContext = $tlsContext->withoutPeerVerification();
        }

        $caFile = $config->get(Config::scopedKey('REDIS_TLS_CA_FILE', $connection));

        if ($caFile !== null) {
            $tlsContext = $tlsContext->withCaFile($caFile);
        }

        return new self($uri, (new ConnectContext())->withConnectTimeout($timeout)->withTlsContext($tlsContext));
    }

    #[\Override]
    public function connect(?Cancellation $cancellation = null): RedisConnection
    {
        try {
            $socket = Socket\connectTls($this->uri, $this->connectContext, $cancellation);
        } catch (Socket\SocketException $e) {
            throw new RedisConnectionException(
                "Failed to connect to redis instance via TLS ({$this->uri}): {$e->getMessage()}",
                0,
                $e,
            );
        }

        return new SocketRedisConnection($socket);
    }
}
