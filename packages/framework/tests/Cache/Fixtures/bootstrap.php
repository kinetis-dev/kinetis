<?php

declare(strict_types=1);

use Kinetis\Config\Config;
use Kinetis\Container\AppScope;
use Kinetis\Tests\Cache\Fixtures\BootstrapMarker;

return static function (AppScope $app, Config $config): void {
    $app->instance(BootstrapMarker::class, new BootstrapMarker());
};
