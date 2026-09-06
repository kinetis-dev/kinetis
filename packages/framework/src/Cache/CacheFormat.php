<?php

declare(strict_types=1);

namespace Kinetis\Cache;

/**
 * The compiled artifact's shape version, and the only compatibility
 * authority it has.
 *
 * {@see CacheStore::write()} stamps it into `.kinetis-cache/compiled.php`
 * and {@see CacheStore::load()} refuses any file carrying a different
 * one, so a build reads an artifact it fully understands or compiles a
 * fresh one — never a file whose fields it has to guess at.
 *
 * **Bump this whenever any section's persisted shape changes**: a field
 * added, removed, renamed or given a new meaning, in `HttpCache`,
 * `CommandCache`, `EventCache`, or any `CacheableDiscoveryInterface`
 * data carried in `PluginCache`. Without the bump an artifact written by
 * an earlier build is read as complete, and the missing field surfaces
 * as an undefined-key error on a production request instead of the
 * recompile this constant exists to trigger.
 *
 * It says nothing about whether the artifact matches the project's
 * current source. Keeping a published artifact in step with the code it
 * was compiled from is the deployment build's job — see
 * docs/caching.md.
 */
final class CacheFormat
{
    public const int VERSION = 17;
}
