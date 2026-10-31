<?php

declare(strict_types=1);

namespace Kinetis\QueueFailedJobs\Console;

use InvalidArgumentException;
use Kinetis\Console\CommandArguments;
use Kinetis\Queue\QueueContract;

/**
 * The selection options `queue:failed` and `queue:retry --all` share:
 * `--connection=<name>` for records a worker run with that
 * `--connection` wrote, `--bound` for records a worker on the bound
 * queue wrote, and `--queue=<name>`.
 *
 * @internal
 */
final class FailedJobOptions
{
    // Never instantiated — every method here is static.
    private function __construct() {}

    /**
     * @return string|false|null in SqlFailedJobStore::list()'s terms:
     *     false for any connection, null for the bound queue, or a name
     */
    public static function connection(CommandArguments $arguments): string|false|null
    {
        $bound = $arguments->hasOption('bound');

        if (!$arguments->hasOption('connection')) {
            return $bound ? null : false;
        }

        if ($bound) {
            throw new InvalidArgumentException('--connection and --bound select different records; pass one of them.');
        }

        $name = $arguments->option('connection');

        if ($name === null || $name === '') {
            throw new InvalidArgumentException('--connection needs a value: --connection=<name>.');
        }

        QueueContract::assertValidConnectionName($name, '--connection');

        return $name;
    }

    public static function queue(CommandArguments $arguments): ?string
    {
        if (!$arguments->hasOption('queue')) {
            return null;
        }

        $queue = $arguments->option('queue') ?? '';
        QueueContract::assertValidQueueName($queue);

        return $queue;
    }

    /**
     * A whole number of at least $min and at most nine digits, from
     * `--<name>=<n>`; $default when
     * the option is absent, and an error when it is absent with no default.
     */
    public static function integer(CommandArguments $arguments, string $name, int $min, ?int $default = null): int
    {
        if (!$arguments->hasOption($name)) {
            if ($default === null) {
                throw new InvalidArgumentException("--{$name} is required: --{$name}=<n>.");
            }

            return $default;
        }

        $value = $arguments->option($name) ?? '';

        // Nine digits keeps every product a caller derives (hours to
        // seconds) inside an int.
        if (preg_match('/^\d{1,9}$/D', $value) !== 1 || (int) $value < $min) {
            throw new InvalidArgumentException("--{$name} must be a whole number of at least {$min}, got \"{$value}\".");
        }

        return (int) $value;
    }
}
