<?php

declare(strict_types=1);

namespace Kinetis\Tests\Events\Fixtures;

use Kinetis\Events\Listener;
use Kinetis\Events\ShouldQueue;

/**
 * A real, observable proof that this class is constructed exactly when
 * expected and never before — $constructions is a plain static counter
 * (a real, accepted NoStaticPropertiesRule exception for test fixtures,
 * matching RecordingMiddleware::$log and DisposalRecorder elsewhere in
 * this codebase's own test suites), reset by each test before use.
 */
final class ConstructionCountingQueueableListener implements ShouldQueue
{
    public static int $constructions = 0;

    public function __construct()
    {
        self::$constructions++;
    }

    #[Listener]
    public function onOrderPlaced(OrderPlaced $event): void
    {
    }
}
