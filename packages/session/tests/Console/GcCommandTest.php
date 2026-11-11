<?php

declare(strict_types=1);

namespace Kinetis\Session\Tests\Console;

use Kinetis\Container\AppScope;
use Kinetis\Session\Console\GcCommand;
use Kinetis\Session\SessionStoreInterface;
use Kinetis\Session\Store\FileSessionStore;
use Kinetis\Session\Tests\Fixtures\CommandOutput;
use Kinetis\Session\Tests\Fixtures\RecordingSessionStore;
use PHPUnit\Framework\TestCase;

final class GcCommandTest extends TestCase
{
    public function test_collects_from_a_garbage_collectable_store_and_reports_the_count(): void
    {
        $directory = \sys_get_temp_dir() . '/kinetis-gc-command-' . \bin2hex(\random_bytes(6));
        $store = new FileSessionStore($directory);
        $live = \bin2hex(\random_bytes(16));
        $store->create($live, ['keep' => true], 60);

        // create() rejects a negative $lifetimeSeconds, so an
        // already-expired file is seeded directly, in the envelope shape
        // FileSessionStore produces.
        $dead = \bin2hex(\random_bytes(16));
        \file_put_contents(
            $directory . '/sess_' . $dead,
            \json_encode(['expiresAt' => \time() - 1, 'data' => ['gone' => true]], JSON_THROW_ON_ERROR),
        );

        [$exitCode, $output, $error] = self::runCommand($store);

        self::assertSame(0, $exitCode);
        self::assertSame("Removed 1 expired session(s).\n", $output);
        self::assertSame('', $error);
        self::assertSame(['keep' => true], $store->read($live));

        $store->destroy($live);
        @\rmdir($directory);
    }

    public function test_a_backend_expiring_store_reports_nothing_to_collect(): void
    {
        [$exitCode, $output, $error] = self::runCommand(new RecordingSessionStore());

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('expires entries on its own', $output);
        self::assertSame('', $error);
    }

    public function test_no_bound_store_is_a_clear_error(): void
    {
        [$exitCode, $output, $error] = self::runCommand(null);

        self::assertSame(1, $exitCode);
        self::assertSame('', $output);
        self::assertSame("No session store is bound — set SESSION_DRIVER (file, redis, or sql).\n", $error);
    }

    /**
     * @return array{int, string, string} exit code, stdout, stderr
     */
    private static function runCommand(?SessionStoreInterface $store): array
    {
        $app = new AppScope();

        if ($store !== null) {
            $app->instance(SessionStoreInterface::class, $store);
        }

        $app->boot();

        $capture = new CommandOutput();
        $exitCode = new GcCommand($app->createRequestScope(), $capture->output)->run();

        return [$exitCode, $capture->takeStdout(), $capture->takeStderr()];
    }
}
