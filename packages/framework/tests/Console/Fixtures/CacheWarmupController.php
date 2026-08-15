<?php

declare(strict_types=1);

namespace Kinetis\Tests\Console\Fixtures;

use Kinetis\Console\Attributes\Command;

final class CacheWarmupController
{
    #[Command('app:warmup', description: 'Operates on the static project shape only', bootstrap: false)]
    public function warmup(): void
    {
    }
}
