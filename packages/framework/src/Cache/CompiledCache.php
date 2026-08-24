<?php

declare(strict_types=1);

namespace Kinetis\Cache;

/**
 * A thin in-memory grouping of the four independent artifacts one
 * Compiler::compile() run produces — never itself persisted as a single
 * file. CacheStore::writeAll() splits it into
 * http.php/commands.php/events.php/plugins.php; CacheStore's
 * loadHttp()/loadCommands()/loadEvents()/loadPlugins() read each back
 * independently, so a request only ever loads the one it actually needs,
 * never all four.
 */
final readonly class CompiledCache
{
    public function __construct(
        public HttpCache $http,
        public CommandCache $commands,
        public EventCache $events,
        public PluginCache $plugins,
    ) {}
}
