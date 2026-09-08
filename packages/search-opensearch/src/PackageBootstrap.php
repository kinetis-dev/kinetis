<?php

declare(strict_types=1);

namespace Kinetis\SearchOpenSearch;

use Kinetis\Config\Config;
use Kinetis\Container\AppScope;
use Kinetis\Container\PackageBootstrapInterface;
use Kinetis\Search\SearchClient;
use OpenSearch\Client;

/**
 * Declared via `extra.kinetis`: with `SEARCH_OPENSEARCH_HOST` set, binds
 * {@see Client} and the engine-neutral {@see SearchClient} over it, so a
 * controller or job can constructor-inject either with nothing else to
 * register. Unset means inert.
 *
 * The concrete client is the binding id because opensearch-php exposes
 * no interface for it — the same shape kinetis/persistence's own dialect
 * contracts take, minus the interface.
 *
 * Installing both engine packages leaves them competing for the
 * {@see SearchClient} id; bind it in the application's own
 * `bootstrap.php` to say which engine owns it.
 *
 * The client is constructed here, not deferred to first use, so a host
 * that is not one usable origin, a plain-HTTP host without the opt-in,
 * or an unusable timeout or response bound fails at registration instead
 * of inside whichever request or queued job happens to search first.
 * Construction opens no connection. The application's own
 * `bootstrap.php` runs after this and still wins on the binding.
 */
final readonly class PackageBootstrap implements PackageBootstrapInterface
{
    #[\Override]
    public function register(AppScope $app, Config $config): void
    {
        if ($config->string('SEARCH_OPENSEARCH_HOST', '') === '') {
            return;
        }

        $client = OpenSearchClientFactory::fromConfig($config);

        $app->instance(Client::class, $client);
        $app->instance(SearchClient::class, new OpenSearchClient($client));
    }
}
