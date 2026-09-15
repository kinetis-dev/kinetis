<?php

declare(strict_types=1);

// The front controller OrmWorkerSequenceTest sends its requests to: a
// FrankenPHP worker, or PHP-FPM behind nginx, in the integration workflow's
// orm-runtime job. RuntimeDetector picks the adapter the way HttpStartup
// does, and the Kernel creates and disposes a RequestScope around every
// request, so each request resolves the EntityManager this package binds.
//
// The container is wired here rather than by HttpStartup's discovery, which
// would scan this package's whole test tree. PackageBootstrap and
// OrmMetadata are what discovery runs for this package. Named index.php
// because FrankenPHP's php-server routes a worker through the document
// root's index.php and nothing else.
//
// Every response carries X-Worker, a token drawn once per execution of this
// script, so two responses carrying one token came from one persistent
// worker, and X-Persistent, the adapter's own isPersistent().

require __DIR__ . '/../../../vendor/autoload.php';

use Kinetis\Config\Config;
use Kinetis\Container\AppScope;
use Kinetis\DatabaseBridge\OrmMetadata;
use Kinetis\DatabaseBridge\PackageBootstrap;
use Kinetis\DatabaseBridge\Tests\Fixtures\OrmWorker\Entities\WorkerPost;
use Kinetis\DatabaseBridge\Tests\Fixtures\OrmWorkerController;
use Kinetis\Http\Kernel;
use Kinetis\Http\Routing\Router;
use Kinetis\Http\TrustedProxies;
use Kinetis\Orm\Metadata\MetadataRegistry;
use Kinetis\Runtime\RuntimeDetector;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

$config = Config::fromEnvironment();
$app = new AppScope();
$app->instance(Config::class, $config);
$app->instance(OrmMetadata::class, OrmMetadata::fromArray(MetadataRegistry::fromClasses([WorkerPost::class])->toArray()));
new PackageBootstrap()->register($app, $config);
$app->boot();

$router = new Router();
$router->register(OrmWorkerController::class);

$adapter = RuntimeDetector::detect(TrustedProxies::fromConfig($config));
$kernel = new Kernel($app, $router, isPersistent: $adapter->isPersistent());
$worker = bin2hex(random_bytes(8));
$persistent = $adapter->isPersistent() ? 'true' : 'false';

$adapter->run(static fn (ServerRequestInterface $request): ResponseInterface => $kernel->handle($request)
    ->withHeader('X-Worker', $worker)
    ->withHeader('X-Persistent', $persistent));
