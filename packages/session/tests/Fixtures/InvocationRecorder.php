<?php

declare(strict_types=1);

namespace Kinetis\Session\Tests\Fixtures;

/**
 * The only way to observe, from outside a real Kernel request, whether a
 * route's inner handler actually ran — a middleware construction failure
 * never reaches the controller at all, so a shared instance registered on
 * the container is what proves "never invoked" rather than merely
 * asserting the eventual response status.
 */
final class InvocationRecorder
{
    public int $calls = 0;

    public function record(): void
    {
        $this->calls++;
    }
}
