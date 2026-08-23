<?php

declare(strict_types=1);

namespace Kinetis\SearchOpenSearch\Tests;

use Kinetis\Config\Config;
use Kinetis\Container\AppScope;
use Kinetis\SearchOpenSearch\PackageBootstrap;
use OpenSearch\Client;
use PHPUnit\Framework\TestCase;

final class PackageBootstrapTest extends TestCase
{
    public function test_no_host_configured_binds_nothing(): void
    {
        $app = new AppScope();
        new PackageBootstrap()->register($app, new Config([]));

        self::assertFalse($app->has(Client::class));
    }

    /**
     * Constructing the client opens no connection, so this asserts the
     * binding without needing a live cluster.
     */
    public function test_a_configured_host_binds_a_client(): void
    {
        $app = new AppScope();
        new PackageBootstrap()->register($app, new Config([
            'SEARCH_OPENSEARCH_HOST' => 'http://localhost:9200',
        ]));
        $app->boot();

        self::assertInstanceOf(Client::class, $app->get(Client::class));
    }
}
