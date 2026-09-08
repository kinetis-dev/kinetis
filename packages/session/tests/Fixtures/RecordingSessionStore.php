<?php

declare(strict_types=1);

namespace Kinetis\Session\Tests\Fixtures;

use Kinetis\Session\SessionStoreInterface;

/**
 * Records every call instead of talking to a real backend — used to
 * prove exactly which id gets read and written, not just that a round
 * trip eventually works. seed() pre-populates an entry the way a real
 * store would already hold a session created by an earlier request.
 */
final class RecordingSessionStore implements SessionStoreInterface
{
    /** @var array<string, array<string, mixed>> */
    private array $entries = [];

    /** @var list<string> */
    public array $reads = [];

    /**
     * Every create() and update() that actually stored data, in order.
     *
     * @var list<array{0: string, 1: array<string, mixed>}>
     */
    public array $writes = [];

    /** @var list<string> */
    public array $destroys = [];

    /**
     * Every write and destroy call, in the exact order they happened
     * and named by operation — the lists above lose that ordering once
     * one commit() makes more than one call, which is what a
     * regenerate() ordering proof needs.
     *
     * @var list<array{0: 'create'|'update'|'destroy', 1: string}>
     */
    public array $operations = [];

    /**
     * @param ?\Throwable $throwOnDestroy when set, destroy() throws this
     *     instead of recording anything or mutating any entry — for
     *     proving that a destroy() failure inside commit() leaves
     *     whatever it was about to remove untouched.
     * @param ?\Throwable $throwOnWrite when set, create() and update()
     *     throw this instead of recording anything or mutating any
     *     entry — for proving that a write failure inside commit()
     *     happens before any old, still-recoverable entry is destroyed.
     */
    public function __construct(
        private readonly ?\Throwable $throwOnDestroy = null,
        private readonly ?\Throwable $throwOnWrite = null,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public function seed(string $id, array $data): void
    {
        $this->entries[$id] = $data;
    }

    #[\Override]
    public function read(string $id): ?array
    {
        $this->reads[] = $id;

        return $this->entries[$id] ?? null;
    }

    /**
     * @param array<string, mixed> $data
     */
    #[\Override]
    public function create(string $id, array $data, int $lifetimeSeconds): void
    {
        if ($this->throwOnWrite !== null) {
            throw $this->throwOnWrite;
        }

        $this->record('create', $id, $data);
    }

    /**
     * @param array<string, mixed> $data
     */
    #[\Override]
    public function update(string $id, array $data, int $lifetimeSeconds): bool
    {
        if ($this->throwOnWrite !== null) {
            throw $this->throwOnWrite;
        }

        if (!isset($this->entries[$id])) {
            return false;
        }

        $this->record('update', $id, $data);

        return true;
    }

    #[\Override]
    public function destroy(string $id): void
    {
        if ($this->throwOnDestroy !== null) {
            throw $this->throwOnDestroy;
        }

        $this->destroys[] = $id;
        $this->operations[] = ['destroy', $id];
        unset($this->entries[$id]);
    }

    /**
     * @param 'create'|'update' $operation
     * @param array<string, mixed> $data
     */
    private function record(string $operation, string $id, array $data): void
    {
        $this->writes[] = [$id, $data];
        $this->operations[] = [$operation, $id];
        $this->entries[$id] = $data;
    }
}
