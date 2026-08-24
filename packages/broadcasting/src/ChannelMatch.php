<?php

declare(strict_types=1);

namespace Kinetis\Broadcasting;

/**
 * A channel name that matched a registered pattern, with its placeholder
 * values extracted — what {@see Http\BroadcastAuthController} resolves
 * the authorizing class/method and its arguments from.
 */
final readonly class ChannelMatch
{
    /**
     * @param array<string, string> $params
     */
    public function __construct(
        public string $class,
        public string $method,
        public bool $usesCurrentUser,
        public array $params,
    ) {}
}
