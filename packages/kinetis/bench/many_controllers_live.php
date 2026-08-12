<?php

declare(strict_types=1);

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

// One genuinely fresh PHP process per invocation (see many_controllers.php's
// orchestrator) — this is what makes the measurement valid. Within a single
// process, PHP never "unloads" a declared class, so looping this same logic
// in one process (like bench/cold_start.php does) would only pay the real
// autoload cost on the very first iteration; every later one would find all
// 150 classes already declared, silently hiding the exact cost being tested.
$app = new AppScope();
$app->boot();

$router = new Router();

for ($i = 1; $i <= 150; $i++) {
    $n = sprintf('%03d', $i);
    $router->register("Kinetis\\Bench\\Fixtures\\Controller{$n}");
}

$kernel = new Kernel($app, $router);

$request = new ServerRequest('POST', '/bench/075', body: json_encode(['value' => 'x']));
$kernel->handle($request);
