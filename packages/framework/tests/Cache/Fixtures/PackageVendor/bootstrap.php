<?php

declare(strict_types=1);

use Kinetis\Config\Config;
use Kinetis\Container\AppScope;
use Kinetis\Tests\Cache\Fixtures\AcmePackage\AcmeMarker;

return static function (AppScope $app, Config $config): void {
    $app->instance('acme.override', new AcmeMarker('from-app'));
};
