<?php

declare(strict_types=1);

use App\Services\SoketiPublisher;
use Kinetis\Persistence\Contract\MysqlLink;
use Kinetis\Config\Config;
use Kinetis\Container\AppScope;
use Kinetis\Persistence\SqlConnectionFactory;
use Kinetis\Queue\QueueInterface;
use Kinetis\Queue\RedisQueue;
use Kinetis\SimpleCache\RedisSimpleCache;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use Psr\Log\LoggerInterface;

use function Amp\Redis\createRedisClient;

/**
 * Registers shared services for every entry point: the app, the queue
 * worker, and the cron container.
 */
return static function (AppScope $app, Config $config): void {
    $app->instance(MysqlLink::class, SqlConnectionFactory::fromConfig($config));

    $redisConfig = RedisSimpleCache::buildRedisConfig($config);

    if ($redisConfig !== null) {
        $app->instance(QueueInterface::class, new RedisQueue(createRedisClient($redisConfig)));
    }

    $app->instance(SoketiPublisher::class, SoketiPublisher::fromConfig($config));

    $logger = new Logger('ping-pong');
    $logger->pushHandler(new StreamHandler('php://stderr', Level::Debug));
    $app->instance(LoggerInterface::class, $logger);
};
