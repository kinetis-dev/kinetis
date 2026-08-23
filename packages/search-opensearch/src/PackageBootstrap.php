<?php

declare(strict_types=1);

namespace Kinetis\SearchOpenSearch;

use Kinetis\Config\Config;
use Kinetis\Container\AppScope;
use Kinetis\Container\PackageBootstrapInterface;
use OpenSearch\Client;

/**
 * Declared via `extra.kinetis`: with `SEARCH_OPENSEARCH_HOST` set,
 * binds {@see Client} so a controller or job can constructor-inject it
 * with nothing else to register. Unset means inert.
 *
 * The concrete client is the binding id because opensearch-php exposes
 * no interface for it — the same shape kinetis/persistence's own
 * dialect contracts take, minus the interface.
 *
 * The binding is a factory, resolved on first use rather than here, so
 * an application that never searches never builds a transport.
 */
final readonly class PackageBootstrap implements PackageBootstrapInterface
{
    #[\Override]
    public function register(AppScope $app, Config $config): void
    {
        if ($config->string('SEARCH_OPENSEARCH_HOST', '') === '') {
            return;
        }

        $app->bind(
            Client::class,
            static fn (): Client => OpenSearchClientFactory::fromConfig($config),
        );
    }
}
