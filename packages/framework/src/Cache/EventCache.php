<?php

declare(strict_types=1);

namespace Kinetis\Cache;

use Kinetis\Cache\Exception\ArtifactValidation;
use Kinetis\Cache\Exception\CacheArtifactExceptionInterface;

/**
 * Every discovered #[Listener] method, grouped by the event class it
 * listens for, already sorted by priority, each entry also recording
 * whether its class implements ShouldQueue. Like CommandCache, there
 * are no binding/hydration plans to carry here at all: EventDispatcher::
 * dispatch() does zero reflection — it either resolves a listener's
 * class through the container and calls the named method directly, or,
 * for a queued one, routes through ListenerInvokerInterface by
 * class-string without resolving anything itself — so the listener list
 * itself, queued flag included, is the entire artifact.
 */
final readonly class EventCache
{
    private const array TOP_LEVEL_KEYS = ['listeners'];

    public function __construct(
        /** @var array<class-string, list<array{class: class-string, method: string, priority: int, queued: bool}>> */
        public array $listeners,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'listeners' => $this->listeners,
        ];
    }

    /**
     * Validates only that `listeners` is present and an array — its own
     * deep per-event/per-entry shape is `EventListenerRegistry::
     * fromArray()`'s job, run separately once this DTO's `listeners`
     * property is handed to it; duplicating that same validation here
     * would just mean paying for it twice on every load.
     *
     * @param array<string, mixed> $data
     * @throws CacheArtifactExceptionInterface
     */
    public static function fromArray(array $data): self
    {
        ArtifactValidation::exactKeys($data, 'EventCache', self::TOP_LEVEL_KEYS);

        $listeners = ArtifactValidation::array($data, 'EventCache', 'listeners');

        /** @var array<class-string, list<array{class: class-string, method: string, priority: int, queued: bool}>> $listeners */
        return new self(listeners: $listeners);
    }
}
