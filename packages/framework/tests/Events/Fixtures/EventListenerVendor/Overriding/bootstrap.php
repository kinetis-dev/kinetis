<?php

declare(strict_types=1);

use Kinetis\Config\Config;
use Kinetis\Container\AppScope;
use Kinetis\Events\EventListenerRegistry;
use Kinetis\Tests\Events\Fixtures\EventListenerVendor\ReplacementListener;

return static function (AppScope $app, Config $config): void {
    // A genuine replacement, not an augmentation: a brand-new registry
    // holding only ReplacementListener, proving this only wins if the
    // discovered registry was bound *before* this bootstrap chain ran —
    // otherwise this instance() call would just be silently overwritten
    // by the discovered one asserted afterward, the exact bug this fixes.
    $registry = new EventListenerRegistry();
    $registry->register(ReplacementListener::class);

    $app->instance(EventListenerRegistry::class, $registry);
};
