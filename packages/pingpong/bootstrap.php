<?php

declare(strict_types=1);

use App\Services\SoketiPublisher;
use Kinetis\Config\Config;
use Kinetis\Container\AppScope;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use Psr\Log\LoggerInterface;

/**
 * Registers app-specific services for every entry point: the app, the
 * queue worker, and the cron container. The MySQL connection and the
 * queue backend need no wiring here — kinetis/persistence and
 * kinetis/queue bind them from DB_CONNECTION/QUEUE_CONNECTION through
 * their own package bootstraps.
 */
return static function (AppScope $app, Config $config): void {
    $app->instance(SoketiPublisher::class, SoketiPublisher::fromConfig($config));

    $logger = new Logger('ping-pong');
    $logger->pushHandler(new StreamHandler('php://stderr', Level::Debug));
    $app->instance(LoggerInterface::class, $logger);
};
