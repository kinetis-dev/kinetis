<?php

declare(strict_types=1);

namespace Kinetis\Tests\Events\Fixtures;

use Kinetis\Container\RequestScope;
use Kinetis\Events\ListenerInvokerInterface;

/**
 * Records that it was asked to invoke something instead of actually
 * calling it — proves EventDispatcher routes a ShouldQueue listener
 * through this seam by class-string, without constructing it, and
 * without needing a real queue backend.
 */
final class RecordingListenerInvoker implements ListenerInvokerInterface
{
    /** @var list<object> */
    public array $invokedWith = [];

    /** @var list<class-string> */
    public array $invokedListenerClasses = [];

    #[\Override]
    public function invoke(string $listenerClass, string $method, object $event, RequestScope $scope): void
    {
        $this->invokedListenerClasses[] = $listenerClass;
        $this->invokedWith[] = $event;
    }
}
