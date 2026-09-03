<?php

declare(strict_types=1);

use Kinetis\Config\Config;
use Kinetis\Container\AppScope;
use Kinetis\Events\EventListenerRegistry;
use Kinetis\Tests\Events\Fixtures\EventListenerVendor\AugmentingListener;

return static function (AppScope $app, Config $config): void {
    // Resolving the registry here only finds anything to augment if it
    // was bound *before* this bootstrap chain ran — proving bootstrap
    // code can augment the discovered set, not merely have it silently
    // reasserted after.
    $app->get(EventListenerRegistry::class)->register(AugmentingListener::class);
};
