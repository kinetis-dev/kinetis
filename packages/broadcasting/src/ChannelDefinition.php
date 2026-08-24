<?php

declare(strict_types=1);

namespace Kinetis\Broadcasting;

/**
 * One registered `#[BroadcastChannel]` method, already reflected into
 * plain data — a compiled regex plus the ordered placeholder names, so
 * {@see BroadcastChannelRegistry::match()} never re-reflects anything.
 */
final readonly class ChannelDefinition
{
    /**
     * @param list<string> $paramNames
     */
    public function __construct(
        public string $pattern,
        public string $regex,
        public array $paramNames,
        public string $class,
        public string $method,
        public bool $usesCurrentUser,
    ) {}
}
