<?php

declare(strict_types=1);

namespace Kinetis\Session\Tests\Console;

use Kinetis\Container\AppScope;
use Kinetis\Session\Console\GcCommand;
use Kinetis\Session\SessionStoreInterface;
use Kinetis\Session\Store\CacheSessionStore;
use Kinetis\Session\Store\FileSessionStore;
use Kinetis\Session\Tests\Fixtures\InMemorySessionCache;
use PHPUnit\Framework\TestCase;

final class GcCommandTest extends TestCase
{
    public function test_collects_from_a_garbage_collectable_store_and_reports_the_count(): void
    {
        $directory = \sys_get_temp_dir() . '/kinetis-gc-command-' . \bin2hex(\random_bytes(6));
        $store = new FileSessionStore($directory);
        $live = \bin2hex(\random_bytes(16));
        $store->write($live, ['keep' => true], 60);

        // write() itself now rejects a negative $lifetimeSeconds (KINETIS-68)
        // — an already-expired file is seeded directly, in the exact real
        // envelope shape FileSessionStore's own write() produces, the same
        // technique FileSessionStoreTest's own corrupt-file test already
        // uses for writing a raw file outside write()'s own contract.
        $dead = \bin2hex(\random_bytes(16));
        \file_put_contents(
            $directory . '/sess_' . $dead,
            \json_encode(['expiresAt' => \time() - 1, 'data' => ['gone' => true]], JSON_THROW_ON_ERROR),
        );

        [$exitCode, $output] = self::runCommand($store);

        self::assertSame(0, $exitCode);
        self::assertSame("Removed 1 expired session(s).\n", $output);
        self::assertSame(['keep' => true], $store->read($live));

        $store->destroy($live);
        @\rmdir($directory);
    }

    public function test_a_backend_expiring_store_reports_nothing_to_collect(): void
    {
        [$exitCode, $output] = self::runCommand(new CacheSessionStore(new InMemorySessionCache()));

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('expires entries on its own', $output);
    }

    public function test_no_bound_store_is_a_clear_error(): void
    {
        [$exitCode, $output] = self::runCommand(null);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('SESSION_DRIVER', $output);
    }

    /**
     * @return array{int, string}
     */
    private static function runCommand(?SessionStoreInterface $store): array
    {
        $app = new AppScope();

        if ($store !== null) {
            $app->instance(SessionStoreInterface::class, $store);
        }

        $app->boot();

        $output = \fopen('php://memory', 'r+');
        self::assertIsResource($output);

        $exitCode = new GcCommand($app->createRequestScope(), $output)->run();
        \rewind($output);
        $printed = \stream_get_contents($output);
        self::assertIsString($printed);

        return [$exitCode, $printed];
    }
}
