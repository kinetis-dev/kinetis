<?php

declare(strict_types=1);

namespace Kinetis\Neuron\Tests\Fixtures;

use NeuronAI\Chat\Messages\Message;
use NeuronAI\Providers\ProviderResponse;
use NeuronAI\Testing\FakeAIProvider;
use Revolt\EventLoop;

/** A fake provider whose answer arrives after a delay, suspending the calling Fiber. */
final class SuspendingProvider extends FakeAIProvider
{
    public function __construct(string $model, private readonly float $delay, Message ...$responses)
    {
        parent::__construct(...$responses);
        $this->model = $model;
    }

    #[\Override]
    public function chat(Message ...$messages): ProviderResponse
    {
        $suspension = EventLoop::getSuspension();
        EventLoop::delay($this->delay, static fn () => $suspension->resume());
        $suspension->suspend();

        return parent::chat(...$messages);
    }
}
