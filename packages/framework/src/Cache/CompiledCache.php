<?php

declare(strict_types=1);

namespace Kinetis\Cache;

/**
 * A thin in-memory grouping of the five independent artifacts one
 * Compiler::compile() run produces — never itself persisted as a single
 * file. CacheStore::writeAll() splits it into
 * http.php/mcp.php/openapi.php/commands.php/events.php; CacheStore's
 * loadHttp()/loadMcp()/loadOpenApi()/loadCommands()/loadEvents() read
 * each back independently, so a request only ever loads the one (or two)
 * it actually needs, never all five.
 */
final readonly class CompiledCache
{
    public function __construct(
        public HttpCache $http,
        public McpCache $mcp,
        public OpenApiCache $openApi,
        public CommandCache $commands,
        public EventCache $events,
    ) {}
}
