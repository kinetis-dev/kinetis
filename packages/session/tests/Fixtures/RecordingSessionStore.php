<?php

declare(strict_types=1);

namespace Kinetis\Session\Tests\Fixtures;

use Kinetis\Session\SessionStoreInterface;

/**
 * Records every call instead of talking to a real backend — used to
 * prove exactly which id gets read/written, not just that a round trip
 * eventually works. seed() pre-populates an entry the way a real store
 * would already hold a session created by an earlier request.
 */
final class RecordingSessionStore implements SessionStoreInterface
{
    /** @var array<string, array<string, mixed>> */
    private array $entries = [];

    /** @var list<string> */
    public array $reads = [];

    /** @var list<array{0: string, 1: array<string, mixed>}> */
    public array $writes = [];

    /** @var list<string> */
    public array $destroys = [];

    /**
     * Every write()/destroy() call, in the exact order they actually
     * happened — the two per-method lists above lose that ordering once
     * more than one method is called in the same commit(), which is
     * exactly what a regenerate()/destroy() ordering proof needs.
     *
     * @var list<array{0: 'write'|'destroy', 1: string}>
     */
    public array $operations = [];

    /**
     * @param ?\Throwable $throwOnDestroy when set, destroy() throws this
     *     instead of recording anything or mutating any entry — for
     *     proving that a destroy() failure inside commit() leaves
     *     whatever it was about to remove untouched.
     * @param ?\Throwable $throwOnWrite when set, write() throws this
     *     instead of recording anything or mutating any entry — for
     *     proving that a write() failure inside commit() happens before
     *     any old, still-recoverable entry is destroyed.
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

    #[\Override]
    public function write(string $id, array $data, int $lifetimeSeconds): void
    {
        if ($this->throwOnWrite !== null) {
            throw $this->throwOnWrite;
        }

        $this->writes[] = [$id, $data];
        $this->operations[] = ['write', $id];
        $this->entries[$id] = $data;
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
}
