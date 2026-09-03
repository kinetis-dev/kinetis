<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http\Fixtures;

use Kinetis\Container\RequestScope;

/**
 * A static handoff so a test can confirm a second onDispose callback
 * still ran despite an earlier one throwing, and inspect the scope
 * afterward — the same static-handoff shape this project's own
 * queue/mcp disposal-precedence fixtures already establish.
 */
final class DisposalRecorder
{
    public static bool $secondRan = false;

    public static ?RequestScope $scope = null;
}
