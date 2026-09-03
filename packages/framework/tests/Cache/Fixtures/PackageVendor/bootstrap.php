<?php

declare(strict_types=1);

use Kinetis\Config\Config;
use Kinetis\Container\AppScope;
use Kinetis\Tests\Cache\Fixtures\AcmePackage\AcmeCacheableDiscovery;
use Kinetis\Tests\Cache\Fixtures\AcmePackage\AcmeMarker;

return static function (AppScope $app, Config $config): void {
    $app->instance('acme.override', new AcmeMarker('from-app'));
    // Proves an application's own bootstrap.php can override a plugin-
    // discovered instance, not just have it silently reasserted after —
    // see TestApplicationTest's own use of this fixture.
    $app->instance(AcmeCacheableDiscovery::class, new AcmeCacheableDiscovery('from-app-bootstrap'));
};
