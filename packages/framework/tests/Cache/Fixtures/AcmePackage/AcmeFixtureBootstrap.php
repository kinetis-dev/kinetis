<?php

declare(strict_types=1);

namespace Kinetis\Tests\Cache\Fixtures\AcmePackage;

use Kinetis\Config\Config;
use Kinetis\Container\AppScope;
use Kinetis\Container\PackageBootstrapInterface;

final class AcmeFixtureBootstrap implements PackageBootstrapInterface
{
    #[\Override]
    public function register(AppScope $app, Config $config): void
    {
        $app->instance('acme.binding', new AcmeMarker('from-package'));
        $app->instance('acme.override', new AcmeMarker('from-package'));
    }
}
