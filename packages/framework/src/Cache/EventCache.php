<?php

declare(strict_types=1);

namespace Kinetis\Cache;

/**
 * Every discovered #[Listener] method, grouped by the event class it
 * listens for, already sorted by priority. Like CommandCache, there are
 * no binding/hydration plans to carry here at all: EventDispatcher::
 * dispatch() does zero reflection — it resolves each listener's class
 * through the container and calls the named method directly — so the
 * listener list itself is the entire artifact.
 */
final readonly class EventCache
{
    public function __construct(
        public int $formatVersion,
        /** @var array<class-string, list<array{class: class-string, method: string, priority: int}>> */
        public array $listeners,
        public string $compiledAt,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'formatVersion' => $this->formatVersion,
            'listeners' => $this->listeners,
            'compiledAt' => $this->compiledAt,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        /** @var array<class-string, list<array{class: class-string, method: string, priority: int}>> $listeners */
        $listeners = $data['listeners'];

        return new self(
            formatVersion: (int) $data['formatVersion'],
            listeners: $listeners,
            compiledAt: (string) $data['compiledAt'],
        );
    }
}
