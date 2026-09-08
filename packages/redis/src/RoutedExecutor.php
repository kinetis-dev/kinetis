<?php

declare(strict_types=1);

namespace Kinetis\Redis;

/**
 * Runs a command on whichever node owns a caller-named routing key.
 *
 * The routing key is always supplied by the caller. This package never
 * guesses which parameter of a command is its key: EVAL's first
 * parameter is a script, several commands have no key at all, and a
 * wrong guess sends the command to the wrong node.
 *
 * The key, a script and its keys and arguments are caller data too, and
 * marked sensitive under the rule {@see QueryExecutor} states.
 */
interface RoutedExecutor
{
    /**
     * @throws \Amp\Redis\Protocol\QueryException Redis replied with an error.
     * @throws \Kinetis\Redis\Exception\ConnectionFailed Nothing was dispatched; retrying is safe.
     * @throws \Kinetis\Redis\Exception\OutcomeUnknown Bytes may have been dispatched and no reply arrived.
     * @throws \Kinetis\Redis\Exception\RedirectLimitExceeded Slots moved under the operation more times than the bound allows.
     */
    public function executeKeyed(
        #[\SensitiveParameter] string $routingKey,
        string $command,
        #[\SensitiveParameter] int|float|string ...$parameters,
    ): mixed;

    /**
     * Runs a Lua script on $routingKey's node, sending EVALSHA first and
     * EVAL when the node has not cached the script.
     *
     * @param list<string> $keys
     * @param list<int|float|string> $arguments
     */
    public function script(
        #[\SensitiveParameter] string $routingKey,
        #[\SensitiveParameter] string $script,
        #[\SensitiveParameter] array $keys,
        #[\SensitiveParameter] array $arguments = [],
    ): mixed;

    /**
     * Whether one command may name keys that hash to different slots.
     * True for a single node, where MGET and a multi-key DEL are one
     * round trip; false for a cluster, which rejects such a command
     * with CROSSSLOT.
     */
    public function allowsCrossSlotKeys(): bool;

    /**
     * Every current master, from a fresh topology read on a cluster and
     * from itself on a single node. Use it for commands that must reach
     * the whole keyspace, such as a prefix scan.
     *
     * @return non-empty-list<QueryExecutor>
     */
    public function nodes(): array;

    public function close(): void;
}
