<?php

declare(strict_types=1);

use Kinetis\Cache\CacheStore;
use Kinetis\Cache\Compiler;
use Kinetis\Http\Routing\Router;

require dirname(__DIR__) . '/vendor/autoload.php';

spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'Kinetis\\Bench\\Fixtures\\')) {
        $short = substr($class, strlen('Kinetis\\Bench\\Fixtures\\'));
        require __DIR__ . "/fixtures/manyControllers/{$short}.php";
    }
});

$cacheDir = $argv[1] ?? (__DIR__ . '/fixtures/manyControllers.cache');

$router = new Router();

for ($i = 1; $i <= 150; $i++) {
    $n = sprintf('%03d', $i);
    $router->register("Kinetis\\Bench\\Fixtures\\Controller{$n}");
}

$compiled = (new Compiler())->compile($router);
(new CacheStore($cacheDir))->writeAll($compiled);

echo "Cache built at {$cacheDir}\n";
