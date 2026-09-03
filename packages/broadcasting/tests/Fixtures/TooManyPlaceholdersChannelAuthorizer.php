<?php

declare(strict_types=1);

namespace Kinetis\Broadcasting\Tests\Fixtures;

use Kinetis\Broadcasting\Attributes\BroadcastChannel;

/**
 * Two placeholders within the same dot-separated segment — the grammar
 * this package supports allows at most one; compilePattern() must
 * reject this before assertSignature() ever runs.
 */
final class TooManyPlaceholdersChannelAuthorizer
{
    #[BroadcastChannel('orders.{a}-{b}')]
    public function authorize(string $a, string $b): bool
    {
        return true;
    }
}
