<?php

declare(strict_types=1);

namespace Kinetis\Redis;

use Amp\Redis\RedisConfig;
use InvalidArgumentException;

/**
 * The endpoint, password and database a
 * `redis://[:password@]host[:port][/database]` URI declares.
 *
 * Parsing goes through amphp/redis's own RedisConfig, so a URI that
 * package accepts is accepted here identically. Only TCP endpoints are
 * routable and only TCP endpoints can be redirected to, so a unix
 * socket URI is refused.
 *
 * A URI that cannot be parsed is reported by a fixed message with no
 * cause attached. The vendor's own failures quote the whole URI back,
 * password and all, and an exception is logged far more often than it
 * is read.
 */
final class ConnectionUri
{
    private function __construct(
        public readonly Endpoint $endpoint,
        #[\SensitiveParameter] public readonly ?string $password,
        public readonly int $database,
    ) {}

    public static function parse(#[\SensitiveParameter] string $uri): self
    {
        try {
            $config = RedisConfig::fromUri($uri);
        } catch (\Throwable) {
            throw new InvalidArgumentException('Malformed Redis URI.');
        }

        $connect = $config->getConnectUri();

        if (!str_starts_with($connect, 'tcp://')) {
            throw new InvalidArgumentException('A Redis URI must name a TCP endpoint, not a unix socket.');
        }

        return new self(
            Endpoint::parse(substr($connect, strlen('tcp://'))),
            $config->hasPassword() ? $config->getPassword() : null,
            $config->getDatabase(),
        );
    }
}
