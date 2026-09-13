<?php

declare(strict_types=1);

use App\Broadcasting\DemoVisitor;
use Kinetis\Config\Config;
use Kinetis\Container\AppScope;
use Kinetis\Http\CurrentUserInterface;
use Kinetis\Views\AssetUrl;
use Kinetis\Views\Views;
use Kinetis\ViewsPhp\PhpViewEngine;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use Psr\Log\LoggerInterface;

/**
 * Registers app-specific services for every entry point: the app, the
 * queue worker, and the cron container. The MySQL connection, the queue
 * backend, and the broadcaster need no wiring here — kinetis/persistence,
 * kinetis/queue, and kinetis/broadcasting bind them from
 * DB_CONNECTION/QUEUE_CONNECTION/BROADCAST_DRIVER through their own
 * package bootstraps.
 */
return static function (AppScope $app, Config $config): void {
    // Explicit AppScope registration, not RequestScope: this app has no
    // real login, so every request's own RequestScope delegates to this
    // single, fixed identity (its own explicit-registration rule) rather
    // than a real auth middleware resolving a distinct user per request.
    $app->instance(CurrentUserInterface::class, new DemoVisitor());
    $app->instance(
        Views::class,
        new Views(new PhpViewEngine(__DIR__ . '/resources/views', new AssetUrl('/'))),
    );

    $logger = new Logger('ping-pong');
    $logger->pushHandler(new StreamHandler('php://stderr', Level::Debug));
    $app->instance(LoggerInterface::class, $logger);
};
