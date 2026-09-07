<?php

declare(strict_types=1);

namespace Kinetis\Tests\Runtime\Fixtures;

use Closure;
use Kinetis\Http\TrustedProxies;
use Kinetis\Runtime\RuntimeAdapterInterface;

/**
 * Stands in for a detected runtime adapter: keeps the proxy policy it
 * was handed and the handler it was asked to drive, instead of entering
 * a request loop that never returns.
 */
final class RecordingAdapter implements RuntimeAdapterInterface
{
    public ?Closure $handler = null;

    public function __construct(
        public readonly TrustedProxies $trustedProxies,
        private readonly bool $persistent = false,
    ) {}

    #[\Override]
    public function run(callable $handler): void
    {
        $this->handler = $handler(...);
    }

    #[\Override]
    public function isPersistent(): bool
    {
        return $this->persistent;
    }
}
