<?php

declare(strict_types=1);

namespace Kinetis\Redis\Internal;

use Amp\Cancellation;
use Amp\Redis\Protocol\RedisError;
use Amp\Socket;
use Amp\Socket\ConnectContext;
use Kinetis\Redis\ClientOptions;
use Kinetis\Redis\Endpoint;
use Kinetis\Redis\Exception\ConnectionFailed;

/**
 * Opens one connection to one endpoint and brings it up to the state a
 * caller's first command expects: TLS when configured, then AUTH, then
 * SELECT. All three run inside the caller's own operation budget, and
 * all three run again on the next connection after a loss — they are
 * connection setup, not user commands being replayed.
 *
 * TLS peer verification follows ClientOptions. Discovered and
 * redirected nodes are reached at whatever address the cluster
 * announces, so their certificates must carry a matching SAN; nothing
 * here relaxes verification for them.
 *
 * A setup command's arguments carry the password, so they are marked
 * sensitive: they never reach a message, and never appear among an
 * exception's visible trace arguments either. A failure here closes the
 * new connection and is reported as {@see ConnectionFailed} — no
 * caller's command has been dispatched on it.
 *
 * @internal
 */
final class Connector
{
    public function __construct(
        private readonly Endpoint $endpoint,
        private readonly ClientOptions $options,
    ) {}

    /** @throws ConnectionFailed */
    public function connect(#[\SensitiveParameter] Cancellation $cancellation): Connection
    {
        $context = (new ConnectContext())->withConnectTimeout($this->options->timeout);
        $tls = $this->options->tls;

        try {
            $socket = $tls !== null
                ? Socket\connectTls($this->endpoint->toUri(), $context->withTlsContext($tls), $cancellation)
                : Socket\connect($this->endpoint->toUri(), $context, $cancellation);
        } catch (\Throwable $e) {
            throw new ConnectionFailed(
                "Failed to connect to Redis at {$this->endpoint->authority()}: {$e->getMessage()}",
                0,
                $e,
            );
        }

        $connection = new Connection($socket);

        try {
            if ($this->options->password !== null) {
                $this->setUp($connection, 'AUTH', [$this->options->password], $cancellation);
            }

            if ($this->options->database !== 0) {
                $this->setUp($connection, 'SELECT', [$this->options->database], $cancellation);
            }
        } catch (ConnectionFailed $e) {
            $connection->close();

            throw $e;
        } catch (\Throwable) {
            $connection->close();

            // The cause is dropped rather than chained: a failure
            // raised while AUTH was on the wire carries frames whose
            // arguments are the credential itself.
            throw new ConnectionFailed(
                "Failed to prepare the Redis connection to {$this->endpoint->authority()}.",
            );
        }

        // Referenced again by the link for as long as a reply is
        // outstanding, so an idle connection never holds the loop open.
        $connection->unreference();

        return $connection;
    }

    /**
     * @param list<int|float|string> $parameters
     * @throws ConnectionFailed
     */
    private function setUp(
        Connection $connection,
        string $command,
        #[\SensitiveParameter] array $parameters,
        #[\SensitiveParameter] Cancellation $cancellation,
    ): void {
        $connection->write(Connection::encode($command, $parameters), $cancellation);
        $reply = $connection->receive($cancellation);

        if ($reply === null || $reply instanceof RedisError) {
            // The reply text can quote the credential that was rejected,
            // so only the command and the endpoint are reported.
            throw new ConnectionFailed(
                "Redis rejected {$command} on the connection to {$this->endpoint->authority()}.",
            );
        }
    }
}
