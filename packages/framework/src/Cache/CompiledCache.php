<?php

declare(strict_types=1);

namespace Kinetis\Cache;

/**
 * A thin in-memory grouping of the four sections one Compiler::compile()
 * run produces — never itself persisted as a single file.
 * CacheStore::writeAll() writes it as four separate files
 * (http.php/commands.php/events.php/plugins.php) inside one new
 * generation directory, then atomically publishes that whole generation
 * at once — see CacheStore's own docblock. CacheStore's
 * loadHttp()/loadCommands()/loadEvents()/loadPlugins() still read each
 * section back independently and lazily, so a given entry point only
 * ever loads the sections it actually needs, never all four — an HTTP
 * boot reads http/events/plugins, the CLI reads commands/events/
 * plugins. Publishing them together as one generation is what makes
 * that lazy, per-section reading safe, not something that requires
 * reading them all at once.
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
