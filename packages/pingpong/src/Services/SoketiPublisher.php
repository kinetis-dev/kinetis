<?php

declare(strict_types=1);

namespace App\Services;

use Kinetis\Config\Config;
use Pusher\Pusher;

/**
 * Publishes ping/pong updates to Soketi.
 */
final readonly class SoketiPublisher
{
    public const CHANNEL = 'ping-pong';

    public function __construct(
        private Pusher $pusher,
    ) {}

    public static function fromConfig(Config $config): self
    {
        $pusher = new Pusher(
            $config->string('SOKETI_KEY', 'app-key'),
            $config->string('SOKETI_SECRET', 'app-secret'),
            $config->string('SOKETI_APP_ID', 'app-id'),
            [
                'host' => $config->string('SOKETI_HOST', 'soketi'),
                'port' => $config->int('SOKETI_PORT', 6001),
                'useTLS' => false,
            ],
        );

        return new self($pusher);
    }

    public function actionOccurred(string $stage, ?int $id, ?string $scenario = null): void
    {
        $this->pusher->trigger(self::CHANNEL, 'action', [
            'stage' => $stage,
            'id' => $id,
            'scenario' => $scenario,
        ]);
    }
}
