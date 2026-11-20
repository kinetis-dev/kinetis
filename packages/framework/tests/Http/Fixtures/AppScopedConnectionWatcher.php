<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http\Fixtures;

use Kinetis\Runtime\ClientConnectionInterface;

/**
 * An application-scoped service that asks for the client connection,
 * which only a request can answer.
 */
final readonly class AppScopedConnectionWatcher
{
    public function __construct(
        public ClientConnectionInterface $connection,
    ) {}
}
