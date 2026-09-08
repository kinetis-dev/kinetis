<?php

declare(strict_types=1);

namespace Kinetis\Redis;

/**
 * Runs a command against one node. No slot routing, no redirect
 * following: whatever the node replies is what the caller receives.
 *
 * The name and signature match amphp/redis's own executor seam, so a
 * consumer written against one reads the same against the other.
 *
 * A command's parameters are the caller's own data, marked sensitive so
 * an implementation keeps them out of its exceptions' trace arguments.
 */
interface QueryExecutor
{
    /**
     * @throws \Amp\Redis\Protocol\QueryException Redis replied with an error.
     * @throws \Kinetis\Redis\Exception\ConnectionFailed Nothing was dispatched; retrying is safe.
     * @throws \Kinetis\Redis\Exception\OutcomeUnknown Bytes may have been dispatched and no reply arrived.
     */
    public function execute(string $command, #[\SensitiveParameter] int|float|string ...$parameters): mixed;
}
