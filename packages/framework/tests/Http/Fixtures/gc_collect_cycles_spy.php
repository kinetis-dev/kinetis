<?php

declare(strict_types=1);

namespace Kinetis\Http;

/**
 * A test-only override of the global gc_collect_cycles(), picked up via
 * PHP's namespace-function-fallback resolution — Kernel.php calls
 * gc_collect_cycles() unqualified from within this same namespace, so an
 * unqualified call resolves to this function if it's been declared by
 * the time the call happens, and falls back to the global one otherwise.
 * Lets KernelTest verify the isPersistent gating actually branches, not
 * just that handle() doesn't throw either way.
 */
function gc_collect_cycles(): int
{
    $GLOBALS['kinetisGcCollectCyclesCallCount'] = ($GLOBALS['kinetisGcCollectCyclesCallCount'] ?? 0) + 1;

    return \gc_collect_cycles();
}
