<?php

declare(strict_types=1);

namespace Kinetis\Tests\Console;

use PHPUnit\Framework\TestCase;

/**
 * bin/kinetis is a bare script, not a class — the only way to prove its
 * own disposal-precedence fix for real is to actually run it, the same
 * "verify against the real binary" discipline this project already
 * applies to bin/kinetis elsewhere (see CLAUDE.md's own Milestone 5/
 * "Package-provided commands and services" verification notes).
 *
 * Each test builds a throwaway, self-contained consumer project in a
 * real temp directory: its own composer.json (a single App\ PSR-4 root,
 * which is all NamespaceScanner::classesInProject() needs to find a
 * command — no real `composer install` required), a bootstrap.php
 * binding a custom logger that writes to a known file (so the
 * subprocess's own log calls are observable after it exits, since the
 * default NullLogger would otherwise discard them silently), a fixture
 * command, and the two small Composer bin-proxy glue files
 * (vendor/autoload.php, vendor/bin/kinetis) that make
 * Kinetis\Runtime\ProjectRoot::detect() resolve the temp directory as
 * the project root exactly the way a real installed consumer's proxy
 * would — see ProjectRoot's own docblock for the mechanism this mirrors.
 */
final class BinKinetisDisposalTest extends TestCase
{
    /**
     * What a retained AMQP client puts in an application-disposal
     * failure: its PHP 8.4 destructor disconnects,
     * re-awaits the connection that already failed, and re-raises it
     * naming the broker endpoint — credentials and internal host
     * included. The application scope releases its bindings as part of
     * disposal, which is what runs that destructor, so this is a
     * disposal message bin/kinetis really can be handed. None of it may
     * reach STDERR, where the last-resort path has no configured logger
     * left to apply anyone's redaction policy.
     */
    private const string BROKER_URI = 'amqp://queue-user:s3cr3t-broker-pw@broker.internal.example:5672/prod';

    private const string DISPOSAL_FAILURE_MESSAGE = 'Cannot close the AMQP connection to ' . self::BROKER_URI;

    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir() . '/kinetis-bin-disposal-' . bin2hex(random_bytes(8));
        mkdir($this->projectDir . '/src', recursive: true);
        mkdir($this->projectDir . '/vendor/bin', recursive: true);

        file_put_contents(
            $this->projectDir . '/composer.json',
            json_encode(['autoload' => ['psr-4' => ['App\\' => 'src/']]], JSON_THROW_ON_ERROR),
        );

        // Binds a logger that writes one JSON line per call to a file
        // named by the DISPOSAL_TEST_LOG_FILE env var — the only way to
        // observe a subprocess's own logging after it has exited. The
        // default NullLogger AppScope::boot() would otherwise bind
        // discards everything silently.
        file_put_contents($this->projectDir . '/bootstrap.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            use Kinetis\Config\Config;
            use Kinetis\Container\AppScope;
            use Kinetis\Events\ListenerInvokerInterface;
            use Psr\Log\AbstractLogger;
            use Psr\Log\LoggerInterface;

            return static function (AppScope $app, Config $config): void {
                if (getenv('DISPOSAL_TEST_THROWING_LISTENER_INVOKER') !== false) {
                    // What kinetis/queue's bootstrap does, with a factory
                    // that fails: resolving EventDispatcher resolves this.
                    $app->bind(ListenerInvokerInterface::class, static fn (): ListenerInvokerInterface => throw new RuntimeException('listener invoker factory failed'), shared: false);
                }

                if (getenv('DISPOSAL_TEST_THROWING_LOGGER') !== false) {
                    // Every resolution throws — bin/kinetis's own subprocess
                    // environment resolves LoggerInterface only from this
                    // one call site's own eventual catch/dispose-failure
                    // logging, with nothing else (no ExceptionHandlerMiddleware,
                    // no request-scope initializer) resolving it first, so a
                    // plain always-throwing factory
                    // is enough — unlike Kernel's own fixtures, where
                    // Kernel owns HTTP request-scope disposal and an
                    // earlier, unrelated resolution has to succeed first.
                    $app->bind(LoggerInterface::class, static fn (): LoggerInterface => throw new RuntimeException('logger factory failed'), shared: false);

                    return;
                }

                $logFile = getenv('DISPOSAL_TEST_LOG_FILE');

                if ($logFile === false) {
                    return;
                }

                $app->instance(LoggerInterface::class, new class($logFile) extends AbstractLogger {
                    public function __construct(private readonly string $logFile) {}

                    public function log($level, string|Stringable $message, array $context = []): void
                    {
                        // Two shapes reach this logger: bin/kinetis's own
                        // pre-existing catch block already interpolates
                        // its message directly and sets no context
                        // 'message' key; the new disposal-failure call
                        // uses a raw PSR-3 {placeholder} template with a
                        // context['message'] key instead. Both are
                        // captured so a test can tell either apart.
                        file_put_contents($this->logFile, json_encode([
                            'level' => (string) $level,
                            'message' => (string) $message,
                            'contextMessage' => $context['message'] ?? null,
                        ], JSON_THROW_ON_ERROR) . "\n", FILE_APPEND);
                    }
                });
            };
            PHP);

        $frameworkAutoload = dirname(__DIR__, 2) . '/vendor/autoload.php';

        file_put_contents(
            $this->projectDir . '/vendor/autoload.php',
            <<<PHP
                <?php
                require {$this->phpString($frameworkAutoload)};
                spl_autoload_register(static function (string \$class): void {
                    if (!str_starts_with(\$class, 'App\\\\')) {
                        return;
                    }
                    \$path = {$this->phpString($this->projectDir . '/src/')} . str_replace('\\\\', '/', substr(\$class, 4)) . '.php';
                    if (is_file(\$path)) {
                        require \$path;
                    }
                });
                PHP,
        );

        // Composer's real generated proxy sets these two globals before
        // including the package's own bin/kinetis — see that script's
        // own top comment and ProjectRoot::detect()'s docblock for why
        // this is what makes __DIR__ resolve to the temp project here
        // instead of the package's own bin/ directory.
        file_put_contents(
            $this->projectDir . '/vendor/bin/kinetis',
            <<<PHP
                <?php
                \$GLOBALS['_composer_autoload_path'] = {$this->phpString($this->projectDir . '/vendor/autoload.php')};
                \$GLOBALS['_composer_bin_dir'] = {$this->phpString($this->projectDir . '/vendor/bin')};
                require {$this->phpString(dirname(__DIR__, 2) . '/bin/kinetis')};
                PHP,
        );
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->projectDir);
    }

    private function phpString(string $value): string
    {
        return var_export($value, true);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir . '/' . $entry;

            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }

        rmdir($dir);
    }

    private function writeCommand(string $filename, string $source): void
    {
        file_put_contents($this->projectDir . '/src/' . $filename, $source);
    }

    /**
     * @param array<string, string> $extraEnv
     * @return array{exitCode: int, stdout: string, stderr: string}
     */
    private function runBinKinetis(string $commandName, string $logFile, array $extraEnv = []): array
    {
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $env = [...$extraEnv, 'PATH' => getenv('PATH') ?: '', 'DISPOSAL_TEST_LOG_FILE' => $logFile];

        $process = proc_open(
            ['php', $this->projectDir . '/vendor/bin/kinetis', $commandName],
            $descriptors,
            $pipes,
            $this->projectDir,
            $env,
        );

        self::assertIsResource($process);

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        self::assertIsString($stdout);
        self::assertIsString($stderr);

        return ['exitCode' => $exitCode, 'stdout' => $stdout, 'stderr' => $stderr];
    }

    /**
     * @return list<array{level: string, message: string, contextMessage: ?string}>
     */
    private function readLog(string $logFile): array
    {
        if (!is_file($logFile)) {
            return [];
        }

        $lines = array_values(array_filter(explode("\n", file_get_contents($logFile) ?: '')));

        return array_map(static fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR), $lines);
    }

    /**
     * No response has left the process yet — a CLI command has no
     * partial output the way a streamed HTTP response does — so a
     * successful command followed by a failed disposal legitimately
     * chooses a deterministic non-zero exit code (70, EX_SOFTWARE) per
     * bin/kinetis's own documented reasoning, logged, without fataling
     * outside the command boundary. A later dispose callback still runs
     * despite an earlier one throwing.
     */
    public function test_a_successful_commands_disposal_failure_produces_a_deterministic_nonzero_exit_and_is_logged(): void
    {
        $this->writeCommand('SucceedingCommandWithFailingDisposal.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace App;

            use Kinetis\Console\Attributes\Command;
            use Kinetis\Container\RequestScope;
            use RuntimeException;

            final readonly class SucceedingCommandWithFailingDisposal
            {
                public function __construct(private RequestScope $scope) {}

                #[Command(name: 'succeeds-with-failing-disposal', bootstrap: true)]
                public function run(): int
                {
                    $scope = $this->scope;

                    $scope->onDispose(static function (): void {
                        throw new RuntimeException('dispose callback failed');
                    });
                    $scope->onDispose(static function (): void {
                        fwrite(STDOUT, "SECOND_DISPOSE_CALLBACK_RAN\n");
                    });

                    return 0;
                }
            }
            PHP);

        $logFile = $this->projectDir . '/log.jsonl';
        $result = $this->runBinKinetis('succeeds-with-failing-disposal', $logFile);

        self::assertSame(70, $result['exitCode'], 'EX_SOFTWARE — the command succeeded but cleanup did not finish cleanly');
        self::assertStringContainsString('SECOND_DISPOSE_CALLBACK_RAN', $result['stdout'], 'a later dispose callback still ran despite an earlier one throwing');

        $entries = $this->readLog($logFile);
        self::assertCount(1, $entries, 'the disposal failure is logged exactly once');
        self::assertSame('error', $entries[0]['level']);
        self::assertSame('dispose callback failed', $entries[0]['contextMessage']);
    }

    /**
     * The command's own intended exit(1) and primary CommandFailed
     * behavior must be retained even when disposal also fails
     * afterward — the disposal failure is logged separately, alongside
     * the command's own real failure, never replacing it or bypassing
     * the exit code bin/kinetis's own catch block already decided.
     */
    public function test_a_throwing_commands_intended_exit_code_is_retained_even_when_disposal_also_fails(): void
    {
        $this->writeCommand('ThrowingCommandWithFailingDisposal.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace App;

            use Kinetis\Console\Attributes\Command;
            use Kinetis\Container\RequestScope;
            use RuntimeException;

            final readonly class ThrowingCommandWithFailingDisposal
            {
                public function __construct(private RequestScope $scope) {}

                #[Command(name: 'throws-with-failing-disposal', bootstrap: true)]
                public function run(): never
                {
                    $scope = $this->scope;

                    $scope->onDispose(static function (): void {
                        throw new RuntimeException('dispose callback failed');
                    });
                    $scope->onDispose(static function (): void {
                        fwrite(STDOUT, "SECOND_DISPOSE_CALLBACK_RAN\n");
                    });

                    throw new RuntimeException('the command itself failed');
                }
            }
            PHP);

        $logFile = $this->projectDir . '/log.jsonl';
        $result = $this->runBinKinetis('throws-with-failing-disposal', $logFile);

        self::assertSame(1, $result['exitCode'], 'the command\'s own real failure decides exit(1) — a disposal failure on top of it must not change that');
        self::assertStringContainsString('SECOND_DISPOSE_CALLBACK_RAN', $result['stdout'], 'a later dispose callback still ran despite an earlier one throwing');

        $entries = $this->readLog($logFile);
        self::assertCount(2, $entries, 'one entry for the command\'s own real failure, one for the disposal failure — neither replaces the other');
        self::assertTrue(
            array_any($entries, static fn (array $e): bool => $e['contextMessage'] === 'the command itself failed'),
            'the command\'s own real failure is still logged, not replaced by the disposal failure',
        );
        self::assertTrue(
            array_any($entries, static fn (array $e): bool => $e['contextMessage'] === 'dispose callback failed'),
            'the disposal failure is logged separately',
        );
    }

    /**
     * Dispatching CommandFailed is secondary reporting: a listener
     * invoker binding that throws while the dispatcher is resolved must
     * leave the command's exit(1) and both disposals intact, and is
     * logged next to the command's own failure.
     */
    public function test_a_failing_command_failed_dispatch_keeps_exit_1_and_disposes_both_scopes(): void
    {
        $this->writeCommand('ThrowingDisposingCommand.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace App;

            use Kinetis\Console\Attributes\Command;
            use Kinetis\Container\RequestScope;
            use RuntimeException;

            final readonly class ThrowingDisposingCommand
            {
                public function __construct(private RequestScope $scope) {}

                #[Command(name: 'throws-and-disposes', bootstrap: true)]
                public function run(): never
                {
                    $this->scope->onDispose(static function (): void {
                        fwrite(STDOUT, "REQUEST_SCOPE_DISPOSED\n");
                    });
                    $this->scope->appScope()->onDispose(static function (): void {
                        fwrite(STDOUT, "APP_SCOPE_DISPOSED\n");
                    });

                    throw new RuntimeException('the command itself failed');
                }
            }
            PHP);

        $logFile = $this->projectDir . '/log.jsonl';
        $result = $this->runBinKinetis('throws-and-disposes', $logFile, ['DISPOSAL_TEST_THROWING_LISTENER_INVOKER' => '1']);

        self::assertSame(1, $result['exitCode'], $result['stderr']);
        self::assertSame(
            ['REQUEST_SCOPE_DISPOSED', 'APP_SCOPE_DISPOSED'],
            array_values(array_filter(explode("\n", $result['stdout']))),
        );
        self::assertSame(
            ['the command itself failed', 'listener invoker factory failed'],
            array_column($this->readLog($logFile), 'contextMessage'),
        );
    }

    /**
     * SafeLogger::log($app->get(LoggerInterface::class), ...) is not
     * actually safe on its own: PHP evaluates that get() call before
     * log() is ever entered, so a throwing LoggerInterface binding
     * escapes uncaught right where the disposal-failure logging happens
     * — turning a completed, successful command into an uncaught fatal
     * (exit 255) instead of the deterministic exit(70) it should still
     * report. This proves it doesn't, even when reporting the failure at
     * all is impossible.
     */
    public function test_the_exit_code_survives_even_when_the_logger_itself_cannot_be_resolved(): void
    {
        $this->writeCommand('SucceedingCommandWithFailingDisposal.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace App;

            use Kinetis\Console\Attributes\Command;
            use Kinetis\Container\RequestScope;
            use RuntimeException;

            final readonly class SucceedingCommandWithFailingDisposal
            {
                public function __construct(private RequestScope $scope) {}

                #[Command(name: 'succeeds-with-failing-disposal', bootstrap: true)]
                public function run(): int
                {
                    $this->scope->onDispose(static function (): void {
                        throw new RuntimeException('dispose callback failed');
                    });

                    return 0;
                }
            }
            PHP);

        $result = $this->runBinKinetis(
            'succeeds-with-failing-disposal',
            $this->projectDir . '/log.jsonl',
            ['DISPOSAL_TEST_THROWING_LOGGER' => '1'],
        );

        self::assertSame(70, $result['exitCode'], 'EX_SOFTWARE must still be reported even though nothing could be logged about why');
    }

    /**
     * The application scope outlives the request scope, so it is
     * disposed after it — and it is disposed at all, which is what
     * closes a connection pool a package opened once for this process.
     */
    public function test_both_scopes_are_disposed_in_order_after_a_successful_command(): void
    {
        $this->writeCommand('DisposingCommand.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace App;

            use Kinetis\Console\Attributes\Command;
            use Kinetis\Container\RequestScope;

            final readonly class DisposingCommand
            {
                public function __construct(private RequestScope $scope) {}

                #[Command(name: 'disposes-both-scopes', bootstrap: true)]
                public function run(): int
                {
                    $this->scope->onDispose(static function (): void {
                        fwrite(STDOUT, "REQUEST_SCOPE_DISPOSED\n");
                    });
                    $this->scope->appScope()->onDispose(static function (): void {
                        fwrite(STDOUT, "APP_SCOPE_DISPOSED\n");
                    });

                    return 0;
                }
            }
            PHP);

        $result = $this->runBinKinetis('disposes-both-scopes', $this->projectDir . '/log.jsonl');

        self::assertSame(0, $result['exitCode']);
        self::assertSame(
            ['REQUEST_SCOPE_DISPOSED', 'APP_SCOPE_DISPOSED'],
            array_values(array_filter(explode("\n", $result['stdout']))),
        );
    }

    /**
     * One cleanup failing must not skip the other: the application
     * scope still owns real resources whatever the request scope's own
     * disposal did.
     */
    public function test_the_application_scope_is_still_disposed_when_the_request_scopes_disposal_failed(): void
    {
        $this->writeCommand('FailingRequestDisposalCommand.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace App;

            use Kinetis\Console\Attributes\Command;
            use Kinetis\Container\RequestScope;
            use RuntimeException;

            final readonly class FailingRequestDisposalCommand
            {
                public function __construct(private RequestScope $scope) {}

                #[Command(name: 'fails-request-disposal', bootstrap: true)]
                public function run(): int
                {
                    $this->scope->onDispose(static function (): void {
                        throw new RuntimeException('request dispose callback failed');
                    });
                    $this->scope->appScope()->onDispose(static function (): void {
                        fwrite(STDOUT, "APP_SCOPE_DISPOSED\n");
                    });

                    return 0;
                }
            }
            PHP);

        $logFile = $this->projectDir . '/log.jsonl';
        $result = $this->runBinKinetis('fails-request-disposal', $logFile);

        self::assertSame(70, $result['exitCode']);
        self::assertStringContainsString('APP_SCOPE_DISPOSED', $result['stdout']);
        self::assertSame('request dispose callback failed', $this->readLog($logFile)[0]['contextMessage']);
    }

    /**
     * The same precedence the request scope's own disposal follows, and
     * reported on STDERR rather than through the logger: the
     * application scope is already disposed by then, so nothing can be
     * resolved from it.
     */
    public function test_a_failing_application_disposal_after_a_successful_command_reports_exit_70(): void
    {
        $this->writeCommand('FailingAppDisposalCommand.php', $this->failingAppDisposalCommand(returnsSuccessfully: true));

        $result = $this->runBinKinetis('fails-app-disposal', $this->projectDir . '/log.jsonl');

        self::assertSame(70, $result['exitCode']);
        $this->assertStderrNamesTheFailureAndWithholdsItsMessage($result['stderr']);
    }

    public function test_a_failing_application_disposal_does_not_replace_a_failing_commands_own_exit_code(): void
    {
        $this->writeCommand('FailingAppDisposalCommand.php', $this->failingAppDisposalCommand(returnsSuccessfully: false));

        $result = $this->runBinKinetis('fails-app-disposal', $this->projectDir . '/log.jsonl');

        self::assertSame(1, $result['exitCode'], "the command's own failure decides exit(1)");
        $this->assertStderrNamesTheFailureAndWithholdsItsMessage($result['stderr']);
    }

    /**
     * The last-resort line reports that the application scope failed to
     * dispose, after which command, and what class was thrown — enough
     * to find the failure in the logs the command itself already wrote.
     * It reports the exception's own message nowhere: by this point the
     * scope has released the configured logger, so there is no
     * redaction or transport policy left to route arbitrary text
     * through, and that text can be a broker URI carrying credentials.
     */
    private function assertStderrNamesTheFailureAndWithholdsItsMessage(string $stderr): void
    {
        self::assertStringContainsString(
            'Application disposal failed after command "fails-app-disposal" finished: RuntimeException.',
            $stderr,
            'the fixed sentence names the command and the throwable class',
        );

        foreach ([self::DISPOSAL_FAILURE_MESSAGE, self::BROKER_URI, 's3cr3t-broker-pw', 'queue-user', 'broker.internal.example', 'Cannot close the AMQP connection'] as $withheld) {
            self::assertStringNotContainsString($withheld, $stderr, "STDERR must not carry \"{$withheld}\" out of a disposal failure");
        }
    }

    /**
     * One command source, differing only in whether the command itself
     * completes — the two exit codes above are the same cleanup failure
     * measured against a decided and an undecided outcome.
     */
    private function failingAppDisposalCommand(bool $returnsSuccessfully): string
    {
        $outcome = $returnsSuccessfully
            ? 'return 0;'
            : "throw new RuntimeException('the command itself failed');";
        $disposalMessage = $this->phpString(self::DISPOSAL_FAILURE_MESSAGE);

        return <<<PHP
            <?php

            declare(strict_types=1);

            namespace App;

            use Kinetis\Console\Attributes\Command;
            use Kinetis\Container\RequestScope;
            use RuntimeException;

            final readonly class FailingAppDisposalCommand
            {
                public function __construct(private RequestScope \$scope) {}

                #[Command(name: 'fails-app-disposal', bootstrap: true)]
                public function run(): int
                {
                    \$this->scope->appScope()->onDispose(static function (): void {
                        throw new RuntimeException({$disposalMessage});
                    });

                    {$outcome}
                }
            }
            PHP;
    }
}
