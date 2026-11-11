<?php

declare(strict_types=1);

namespace Kinetis\Tests\Testing\Fixtures\CommandProject;

use Kinetis\Console\Attributes\Command;

/**
 * Opens the request-scoped unit of work and leaves it open, so only the
 * scope's dispose callback can close it.
 */
final readonly class TransactionalCommand
{
    public function __construct(
        private UnitOfWork $unitOfWork,
    ) {}

    #[Command('fixture:transactional')]
    public function run(): void
    {
        $this->unitOfWork->begin();
    }
}
