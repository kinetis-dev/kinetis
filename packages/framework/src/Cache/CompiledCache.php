<?php

declare(strict_types=1);

namespace Kinetis\Cache;

use Kinetis\Cache\Exception\ArtifactValidation;
use Kinetis\Cache\Exception\CacheArtifactExceptionInterface;

/**
 * Everything one `Compiler::compile()` run produces, and the shape
 * {@see CacheStore} persists as a single artifact: the HTTP section, the
 * command section, the event-listener section and the plugin section,
 * plus the one `formatVersion` that decides whether a build can read the
 * file at all.
 *
 * A boot loads the whole thing. HTTP uses `http`, `events` and `plugins`;
 * the CLI uses `commands`, `events` and `plugins` — one `require` either
 * way, with no section left to fetch later and therefore no way for two
 * sections of one boot to come from different compiles.
 */
final readonly class CompiledCache
{
    private const array TOP_LEVEL_KEYS = ['formatVersion', 'http', 'commands', 'events', 'plugins'];

    public function __construct(
        public HttpCache $http,
        public CommandCache $commands,
        public EventCache $events,
        public PluginCache $plugins,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'formatVersion' => CacheFormat::VERSION,
            'http' => $this->http->toArray(),
            'commands' => $this->commands->toArray(),
            'events' => $this->events->toArray(),
            'plugins' => $this->plugins->toArray(),
        ];
    }

    /**
     * $data has already been confirmed to carry this build's own
     * `formatVersion` — {@see CacheStore::load()} checks that before
     * anything here runs, since a version it cannot read is a recompile
     * rather than a malformed artifact.
     *
     * @param array<array-key, mixed> $data
     * @throws CacheArtifactExceptionInterface
     */
    public static function fromArray(array $data): self
    {
        ArtifactValidation::exactKeys($data, 'CompiledCache', self::TOP_LEVEL_KEYS);

        return new self(
            http: HttpCache::fromArray(ArtifactValidation::stringKeyedArray($data, 'CompiledCache', 'http')),
            commands: CommandCache::fromArray(ArtifactValidation::stringKeyedArray($data, 'CompiledCache', 'commands')),
            events: EventCache::fromArray(ArtifactValidation::stringKeyedArray($data, 'CompiledCache', 'events')),
            plugins: PluginCache::fromArray(ArtifactValidation::stringKeyedArray($data, 'CompiledCache', 'plugins')),
        );
    }
}
