<?php

declare(strict_types=1);

namespace Kinetis\Cache\Exception;

use Throwable;

/**
 * Implemented by any exception that means "this data, read directly
 * from a compiled cache artifact, does not represent a valid instance
 * of the type being reconstructed" — never a genuine programming
 * defect (an undefined method inside a plugin's own `fromArray()`, an
 * assertion failure, a dependency error). `BootSequence`'s cache-bundle
 * loaders catch exactly this interface — never a bare `Throwable` —
 * around every `fromArray()`-style reconstruction call that works
 * purely from data just read off disk, so a real bug still propagates
 * instead of being silently relabelled "corrupt cache" and retried as
 * a fresh compile.
 *
 * `Kinetis\Cache\Exception\InvalidCacheArtifactException` (this
 * namespace's own general-purpose implementation, used by `HttpCache`/
 * `CommandCache`/`EventCache`/`PluginCache`/`Router`/`CommandRegistry`)
 * and `Kinetis\Events\Exception\InvalidListenerException` both
 * implement it. A `CacheableDiscoveryInterface::fromArray()`
 * implementation is expected to throw something implementing it for
 * malformed `$data` — see that interface's own docblock.
 */
interface CacheArtifactExceptionInterface extends Throwable {}
