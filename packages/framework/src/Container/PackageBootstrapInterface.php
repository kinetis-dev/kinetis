<?php

declare(strict_types=1);

namespace Kinetis\Container;

use Kinetis\Config\Config;

/**
 * A package's container wiring, named by `extra.kinetis.bootstrap` in its
 * composer.json and run before the application's own bootstrap.php — so
 * `instance()`'s last-write-wins always lets the application override a
 * package binding.
 *
 * Implementations are wiring only: bind the contracts the package's
 * discovered resources (and application code) inject, gated on the
 * package's own configuration, and stay inert when unconfigured — a
 * missing DB_CONNECTION means "this app has no database", not an error.
 * No I/O belongs here beyond what configuration explicitly requests
 * (DB_WARM_CONNECTIONS opening pooled connections, for one).
 */
interface PackageBootstrapInterface
{
    public function register(AppScope $app, Config $config): void;
}
