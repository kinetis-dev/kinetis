<?php

declare(strict_types=1);

namespace Kinetis\Tests\Events\Fixtures;

use Kinetis\Events\ListenerInvokerInterface;

/**
 * Records that it was asked to invoke something instead of actually
 * calling it — proves EventDispatcher routes a ShouldQueue listener
 * through this seam rather than calling it directly, without needing a
 * real queue backend.
 */
final class RecordingListenerInvoker implements ListenerInvokerInterface
{
    /** @var list<object> */
    public array $invokedWith = [];

    public function invoke(object $listener, string $method, object $event): void
    {
        $this->invokedWith[] = $event;
    }
}
