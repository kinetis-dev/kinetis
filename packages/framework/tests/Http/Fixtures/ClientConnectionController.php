<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http\Fixtures;

use Kinetis\Http\Attributes\Get;
use Kinetis\Runtime\ClientConnectionInterface;

/**
 * Reads the client connection once through its constructor and once
 * through a route-method parameter.
 */
final readonly class ClientConnectionController
{
    public function __construct(
        private ClientConnectionInterface $connection,
    ) {}

    /**
     * @return array<string, bool>
     */
    #[Get('/client-connection')]
    public function show(ClientConnectionInterface $connection): array
    {
        return [
            'constructor' => $this->connection->isGone(),
            'parameter' => $connection->isGone(),
        ];
    }

    /**
     * @return array<string, bool>
     */
    #[Get('/client-connection/app-scoped')]
    public function appScoped(AppScopedConnectionWatcher $watcher): array
    {
        return ['gone' => $watcher->connection->isGone()];
    }
}
