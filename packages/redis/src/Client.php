<?php

declare(strict_types=1);

namespace Kinetis\Redis;

use Amp\Redis\Connection\RedisLink;
use Amp\Redis\Protocol\QueryException;
use Kinetis\Redis\Internal\Connector;
use Kinetis\Redis\Internal\Link;

/**
 * A client for one Redis node.
 *
 * Construction opens nothing: the socket, TLS handshake, AUTH and
 * SELECT all happen on the first command, so a configured but
 * momentarily unreachable server costs nothing until something is
 * actually asked of it.
 *
 * The node also satisfies {@see RoutedExecutor}, ignoring the routing
 * key, so one consumer works unchanged against a single node and a
 * cluster. Its bulk commands may name keys from different slots, which
 * is what keeps MGET and a multi-key DEL a single round trip here.
 */
final class Client implements QueryExecutor, RoutedExecutor
{
    private ?Link $link = null;

    private function __construct(
        public readonly Endpoint $endpoint,
        private readonly ClientOptions $options,
    ) {}

    public static function create(Endpoint $endpoint, ClientOptions $options): self
    {
        return new self($endpoint, $options);
    }

    /**
     * The transport itself, for `new Amp\Redis\RedisClient($client->link())`
     * when the typed command facade is wanted instead of raw commands.
     */
    public function link(): RedisLink
    {
        return $this->link ??= new Link(new Connector($this->endpoint, $this->options), $this->options->timeout);
    }

    #[\Override]
    public function execute(string $command, #[\SensitiveParameter] int|float|string ...$parameters): mixed
    {
        return $this->pipeline([[$command, array_values($parameters)]])[0];
    }

    /** The routing key is ignored: one node owns every slot. */
    #[\Override]
    public function executeKeyed(
        #[\SensitiveParameter] string $routingKey,
        string $command,
        #[\SensitiveParameter] int|float|string ...$parameters,
    ): mixed {
        return $this->execute($command, ...$parameters);
    }

    #[\Override]
    public function script(
        #[\SensitiveParameter] string $routingKey,
        #[\SensitiveParameter] string $script,
        #[\SensitiveParameter] array $keys,
        #[\SensitiveParameter] array $arguments = [],
    ): mixed {
        return $this->runScript($script, $keys, $arguments, $this->options->deadline(), asking: false);
    }

    #[\Override]
    public function allowsCrossSlotKeys(): bool
    {
        return true;
    }

    #[\Override]
    public function nodes(): array
    {
        return [$this];
    }

    #[\Override]
    public function close(): void
    {
        $this->link?->close();
        $this->link = null;
    }

    /**
     * Runs commands on this node's link and returns their unwrapped
     * replies in order. More than one travels as a single write; see
     * {@see Link::run()} for what that guarantees.
     *
     * @param non-empty-list<array{string, list<int|float|string>}> $commands
     * @return non-empty-list<mixed>
     * @internal $deadline lets ClusterClient spend one budget across a whole redirect sequence.
     */
    public function pipeline(
        #[\SensitiveParameter] array $commands,
        #[\SensitiveParameter] ?Deadline $deadline = null,
    ): array {
        /** @var Link $link */
        $link = $this->link();
        $responses = [];

        foreach ($link->run($commands, $deadline ?? $this->options->deadline()) as $response) {
            $responses[] = $response->unwrap();
        }

        /** @var non-empty-list<mixed> */
        return $responses;
    }

    /**
     * EVALSHA first, EVAL once the node reports it has not cached the
     * script. Under ASK the pair is ASKING + EVAL in one write: EVALSHA
     * there would consume the ASKING and leave the EVAL retry to be
     * redirected all over again.
     *
     * @param list<string> $keys
     * @param list<int|float|string> $arguments
     * @internal
     */
    public function runScript(
        #[\SensitiveParameter] string $script,
        #[\SensitiveParameter] array $keys,
        #[\SensitiveParameter] array $arguments,
        #[\SensitiveParameter] Deadline $deadline,
        bool $asking,
    ): mixed {
        $body = [count($keys), ...$keys, ...$arguments];

        if ($asking) {
            return $this->pipeline([['ASKING', []], ['EVAL', [$script, ...$body]]], $deadline)[1];
        }

        try {
            // The digest is the Redis protocol's own script identifier for
            // EVALSHA, which mandates SHA-1: it names an entry in the node's
            // script cache and carries no security property.
            return $this->pipeline([['EVALSHA', [sha1($script), ...$body]]], $deadline)[0]; // NOSONAR
        } catch (QueryException $e) {
            if (!str_starts_with($e->getMessage(), 'NOSCRIPT')) {
                throw $e;
            }
        }

        return $this->pipeline([['EVAL', [$script, ...$body]]], $deadline)[0];
    }
}
