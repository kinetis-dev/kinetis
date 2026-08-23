<?php

declare(strict_types=1);

use Kinetis\Cache\CacheStore;
use Kinetis\Container\AppScope;
use Kinetis\Http\Kernel;
use Kinetis\Http\Routing\Router;
use Nyholm\Psr7\ServerRequest;

require dirname(__DIR__) . '/vendor/autoload.php';

spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'Kinetis\\Bench\\Fixtures\\')) {
        $short = substr($class, strlen('Kinetis\\Bench\\Fixtures\\'));
        require __DIR__ . "/fixtures/manyControllers/{$short}.php";
    }
});

$cacheDir = $argv[1] ?? (__DIR__ . '/fixtures/manyControllers.cache');

$app = new AppScope();
$app->boot();

$httpCache = (new CacheStore($cacheDir))->loadHttp();
$router = Router::fromArray($httpCache->routes);

$kernel = new Kernel($app, $router, httpCache: $httpCache);

$request = new ServerRequest('POST', '/bench/075', body: json_encode(['value' => 'x']));
$kernel->handle($request);
