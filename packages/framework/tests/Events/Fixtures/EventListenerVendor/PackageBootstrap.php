<?php

declare(strict_types=1);

namespace Kinetis\Tests\Events\Fixtures\EventListenerVendor;

use Kinetis\Config\Config;
use Kinetis\Container\AppScope;
use Kinetis\Container\PackageBootstrapInterface;
use Kinetis\Events\EventListenerRegistry;

/**
 * A real PackageBootstrapInterface implementation, instantiated by
 * RoutesFile::loadBootstrap() via `new $class()` — no autowiring, so
 * this deliberately has a plain no-arg constructor, matching every real
 * installed package's own `extra.kinetis.bootstrap` class. Resolving the
 * registry here only finds anything to augment if BootSequence::run()
 * bound it before this ran, exactly the same proof Augmenting/bootstrap.php
 * already gives for an *application's* own bootstrap.php — this is the
 * package-bootstrap-stage counterpart.
 */
final class PackageBootstrap implements PackageBootstrapInterface
{
    #[\Override]
    public function register(AppScope $app, Config $config): void
    {
        $app->get(EventListenerRegistry::class)->register(PackageAugmentingListener::class);
    }
}
