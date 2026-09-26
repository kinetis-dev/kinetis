<?php

declare(strict_types=1);

/**
 * Runs this package with kinetis/orm removed from the autoloader, which is
 * how an installation without the optional package sees it, and prints what
 * it observed as JSON for OrmWiringTest.
 */

use Composer\Autoload\ClassLoader;
use Kinetis\Cache\DiscoveryContext;
use Kinetis\Cache\Exception\CacheArtifactExceptionInterface;
use Kinetis\Cache\PluginDiscovery;
use Kinetis\Config\Config;
use Kinetis\Container\AppScope;
use Kinetis\DatabaseBridge\OrmMetadata;
use Kinetis\DatabaseBridge\PackageBootstrap;
use Kinetis\Persistence\Contract\MysqlLink;
use Kinetis\Persistence\TransactionGuard;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/** @var ClassLoader $loader */
$loader = require __DIR__ . '/../../vendor/autoload.php';
$loader->setPsr4('Kinetis\\Orm\\', []);

$observed = [
    'ormInstalled' => class_exists('Kinetis\\Orm\\OrmFactory'),
    'compiled' => OrmMetadata::compile(new DiscoveryContext(__DIR__ . '/OrmProject')),
    'emptyEntryReconstructs' => PluginDiscovery::reconstruct([OrmMetadata::class => []])[OrmMetadata::class] instanceof OrmMetadata,
];

try {
    PluginDiscovery::reconstruct([OrmMetadata::class => ['entities' => []]]);
    $observed['ormEntryRefused'] = false;
} catch (CacheArtifactExceptionInterface) {
    $observed['ormEntryRefused'] = true;
}

foreach (['withoutDatabase' => [], 'withDatabase' => ['DB_CONNECTION' => 'mysql', 'DB_PASSWORD' => 'secret']] as $case => $values) {
    $app = new AppScope();
    $app->instance(LoggerInterface::class, new NullLogger());
    new PackageBootstrap()->register($app, new Config($values));
    $app->boot();
    $scope = $app->createRequestScope();

    $observed[$case] = [
        'factoryBound' => $app->has('Kinetis\\Orm\\OrmFactory'),
        'managerBound' => $scope->isRegistered('Kinetis\\Orm\\EntityManager'),
        'linkBound' => $app->has(MysqlLink::class),
        'guardResolves' => $scope->get(TransactionGuard::class) instanceof TransactionGuard,
    ];

    $scope->dispose();
}

echo json_encode($observed, JSON_THROW_ON_ERROR);
