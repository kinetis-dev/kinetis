<?php

declare(strict_types=1);

namespace Kinetis\Cache;

/**
 * Shared by HttpCache/McpCache/CommandCache/EventCache — one
 * version number for the five artifacts a single Compiler run always
 * produces together, so CacheStore can detect a stale shape regardless of
 * which file it's reading.
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
    public const int VERSION = 10;
}
