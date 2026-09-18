<?php

declare(strict_types=1);

namespace Kinetis\Tests\Testing;

use Kinetis\Tests\Testing\Fixtures\DisposalProbe;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * {@see \Kinetis\Testing\ApplicationTestCase}'s own before- and
 * after-hooks, driven directly against a real application: a test
 * cannot observe from inside itself what its own teardown did
 * afterwards.
 */
final class ApplicationTestCaseDisposalTest extends TestCase
{
    public function test_the_after_hook_disposes_the_application_the_before_hook_booted(): void
    {
        $probe = self::probe();
        $probe->boot();

        $probe->disposeAfterTest();

        self::assertSame(1, $probe->disposals);
    }

    /**
     * PHPUnit runs after-hooks even when a before-hook failed, so the
     * hook is reached with the typed property never assigned. Touching
     * it unguarded would raise an uninitialized-property Error and
     * replace the boot failure the suite has to see.
     */
    public function test_the_after_hook_is_harmless_when_the_before_hook_never_completed(): void
    {
        $probe = self::probe();
        $probe->failBoot = true;

        try {
            $probe->boot();
            self::fail('Expected the boot failure to propagate.');
        } catch (RuntimeException $e) {
            self::assertSame('test double registration failed', $e->getMessage());
        }

        $probe->disposeAfterTest();

        self::assertSame(0, $probe->disposals);
    }

    /**
     * PHPUnit's own TestCase constructor takes the name of a real
     * method on the case; nothing here ever runs it, since both hooks
     * are driven directly.
     */
    private static function probe(): DisposalProbe
    {
        return new DisposalProbe('boot');
    }
}
