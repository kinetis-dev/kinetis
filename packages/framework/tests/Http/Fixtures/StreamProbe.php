<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http\Fixtures;

use Kinetis\Container\RequestScope;

/**
 * A static handoff recording, in order, what each streamed request did —
 * dispatch, emission, disposal — plus the RequestScope each one ran on
 * and how many collection cycles the Kernel had forced by the time
 * disposal happened. Enough for a test to assert that disposal follows
 * emission, that collection follows disposal, and that a released scope
 * belongs to one request only.
 */
final class StreamProbe
{
    /** @var list<string> */
    public static array $events = [];

    /** @var list<RequestScope> */
    public static array $scopes = [];

    public static ?int $collectionsAtDisposal = null;

    /**
     * A Kernel holds a reference to itself through its own global
     * pipeline, so a previous test's Kernel — and the pending lease on
     * it — survives until a collection cycle runs. Forcing one here
     * means a destructor from an earlier request records into that
     * request's log, never into the one being set up.
     */
    public static function reset(): void
    {
        self::$scopes = [];
        \gc_collect_cycles();

        self::$events = [];
        self::$collectionsAtDisposal = null;
        $GLOBALS['kinetisGcCollectCyclesCallCount'] = 0;
    }

    /**
     * The gc_collect_cycles() spy's own counter — see
     * Fixtures/gc_collect_cycles_spy.php.
     */
    public static function collections(): int
    {
        return (int) ($GLOBALS['kinetisGcCollectCyclesCallCount'] ?? 0);
    }
}
