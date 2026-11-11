<?php

declare(strict_types=1);

namespace Kinetis\Tests\Testing\Fixtures\CommandProject;

/**
 * Bound per request scope by this project's bootstrap.php, which
 * registers rollBackIfOpen() as that scope's dispose callback.
 */
final class UnitOfWork
{
    private bool $open = false;

    public function __construct(
        private readonly Journal $journal,
    ) {}

    public function begin(): void
    {
        $this->open = true;
        $this->journal->entries[] = 'began';
    }

    public function rollBackIfOpen(): void
    {
        if ($this->open) {
            $this->open = false;
            $this->journal->entries[] = 'rolled back';
        }
    }
}
