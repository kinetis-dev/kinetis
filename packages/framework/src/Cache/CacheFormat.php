<?php

declare(strict_types=1);

namespace Kinetis\Cache;

/**
 * Shared by HttpCache/McpCache/OpenApiCache/CommandCache/EventCache — one
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
    public const int VERSION = 5;
}
