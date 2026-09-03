<?php

declare(strict_types=1);

namespace Kinetis\Cache;

/**
 * Shared by HttpCache/CommandCache/EventCache/PluginCache — one version
 * number for the four sections a single Compiler run always produces
 * together into one generation, so CacheStore can detect a stale shape
 * regardless of which section it's reading.
 */
final class CacheFormat
{
    // 2: HttpCache gained globalMiddleware.
    // 3: EventCache/events.php introduced.
    // 4: HttpCache gained mcpMiddleware/openApiMiddleware.
    // 5: binding/hydration plan parameters gained allowsNull.
    // 6: HttpCache gained middlewareGroups.
    // 7: CommandCache commands gained bootstrap.
    // 8: HttpCache/CommandCache gained packageBootstraps.
    // 9: binding plans gained the 'container' parameter source — an old
    //    plan records those parameters as 'default', which would keep
    //    throwing instead of resolving them.
    // 11: MCP moved to kinetis/mcp — mcp.php is no longer produced, and
    //     HttpCache lost mcpMiddleware, so an older http.php would hit
    //     an undefined key instead of the clean fall-back-and-recompile
    //     this version check exists for.
    // 12: PluginCache/plugins.php introduced — every installed package's
    //     own CacheableDiscoveryInterface data, declared via
    //     extra.kinetis's "discovery" key.
    // 13: generation-based publishing — CacheStore no longer writes the
    //     four sections directly under the cache directory; each publish
    //     writes a complete, immutable generation into its own
    //     subdirectory, then atomically switches a plain-text current
    //     pointer naming it, which itself now carries this same version
    //     number. A directory from before this version has no pointer
    //     at all, so it's already correctly treated as absent by
    //     CacheStore's own "no pointer found" fallback, with no separate
    //     migration case to write here — this bump exists purely to
    //     keep the version history honest about the shape change, the
    //     same discipline every prior entry already follows.
    // 14: EventCache listeners gained queued — an old generation's
    //     entries have no such key, which would reach
    //     EventDispatcher::dispatch()'s $listener['queued'] read as an
    //     undefined-array-key error instead of the clean
    //     fall-back-and-recompile this version check exists for.
    // 15: kinetis/mcp's McpRegistry replaced its own PluginCache-stored
    //     tool schema shape — a reserved bare-string/array marker
    //     compared against schema *values* — with a collision-free one:
    //     the schema data plus a separately-recorded, purely structural
    //     `inputSchemaObjectPaths` list, so no real schema value can ever
    //     be mistaken for one. An old generation's tool entries have no
    //     `inputSchemaObjectPaths` key at all, which would reach
    //     McpRegistry::fromArray()'s own exactKeys() check as a
    //     confusing "malformed entry" error instead of the clean
    //     fall-back-and-recompile this version check exists for.
    public const int VERSION = 15;
}
