<?php

declare(strict_types=1);

namespace Kinetis\QueueFailedJobs;

use Kinetis\Config\Config;
use Kinetis\Container\AppScope;
use Kinetis\Container\PackageBootstrapInterface;
use Kinetis\DatabaseBridge\ConnectionFactory;
use Kinetis\Queue\FailedJobRecorderInterface;
use Kinetis\Queue\QueueContract;
use Psr\Container\ContainerInterface;

/**
 * Declared via extra.kinetis in this package's composer.json, so
 * installing the package is what turns recording on: `queue:work` finds
 * FailedJobRecorderInterface bound and hands it to the worker.
 *
 * SqlFailedJobStore is a lazy application-scoped factory over its own
 * link to the database connection FAILED_JOBS_DB_CONNECTION_NAME names —
 * `DB_*` for `default`, which an unset or blank name selects, and
 * `DB_{NAME}_*` for any other. The name is checked against the
 * connection-name grammar before the link is built, and the link's
 * close() runs when the application scope is disposed. A process that
 * never resolves the store opens nothing.
 *
 * FailedJobRecorderInterface resolves through the SqlFailedJobStore
 * binding, so the worker and the commands share one store and one link.
 * An application binding either id in its own bootstrap.php wins over
 * this, since that runs later.
 */
final class PackageBootstrap implements PackageBootstrapInterface
{
    #[\Override]
    public function register(AppScope $app, Config $config): void
    {
        $app->bind(SqlFailedJobStore::class, static function (AppScope $app) use ($config): SqlFailedJobStore {
            $connection = $config->string('FAILED_JOBS_DB_CONNECTION_NAME', '');

            if ($connection === '') {
                $connection = 'default';
            } else {
                QueueContract::assertValidConnectionName($connection, 'FAILED_JOBS_DB_CONNECTION_NAME');
            }

            $link = ConnectionFactory::fromConfig($config, $connection);
            $app->onDispose($link->close(...));

            return new SqlFailedJobStore($link);
        });

        $app->bind(
            FailedJobRecorderInterface::class,
            static function (ContainerInterface $container): FailedJobRecorderInterface {
                /** @var SqlFailedJobStore */
                return $container->get(SqlFailedJobStore::class);
            },
        );
    }
}
