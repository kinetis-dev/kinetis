<?php

declare(strict_types=1);

namespace Kinetis\McpDocs\Tests;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * setup.sh drops composer.lock and rewrites composer.json in the
 * directory it installs into, so which directories it accepts is a
 * destructive boundary. Ownership is the marker file's content: a
 * regular file holding exactly the line the script writes. These tests
 * pin what it refuses — no marker, the wrong bytes, a symlink, a
 * directory under that name — against a target holding somebody else's
 * Composer project, and the two it allows: an empty directory, and one
 * this script marked itself.
 *
 * The rest pin the lifecycle around that install. One install directory
 * serves every client session, so the install holds the same
 * .update.lock start.sh takes, from the first write to composer.json
 * through the end of composer install; the timestamp start.sh reads is
 * written only by an install that succeeded; and the handshake that
 * proves the server works runs the same start.sh the script registers.
 *
 * The script is driven with stand-ins for `docker`, `composer`, `php`
 * and `claude` on its PATH, and with KINETIS_MCP_DOCS_DIR and HOME
 * pointing inside a temporary directory, so nothing here reaches
 * Docker, the network, or a real client's configuration. The `docker`
 * stand-in runs the container's command in the install directory the
 * mount stands for, so the lock, the timestamp and the launcher are the
 * real ones.
 */
final class SetupScriptTest extends TestCase
{
    private const string SCRIPT = __DIR__ . '/../setup.sh';

    /** The launcher as the package ships it. */
    private const string LAUNCHER_SOURCE = __DIR__ . '/../start.sh';

    /** The path setup.sh installs that launcher to, and registers. */
    private const string LAUNCHER = 'vendor/kinetis/mcp-docs/start.sh';

    private const string SERVER_LINE = 'server vendor/bin/kinetis-mcp-docs';

    /**
     * Stands in for Composer. `$1` names the subcommand, so an install
     * setup.sh ran reads apart from an update start.sh ran, and the
     * install leaves the real start.sh where the package puts it, so
     * the verification handshake runs the script rather than a copy.
     */
    private const string COMPOSER_STUB = <<<'SH'
        echo "composer $1" >> "$EVENTS"

        if [ "$1" = install ]; then
            mkdir -p vendor/kinetis/mcp-docs
            cp "$LAUNCHER_SOURCE" vendor/kinetis/mcp-docs/start.sh
        fi
        SH;

    /** The one line setup.sh writes; changing it there orphans every existing install. */
    private const string MARKER_TEXT = "kinetis/mcp-docs install directory - safe for this script to rewrite\n";

    private const string MARKER = '.kinetis-mcp-docs';

    private const string STAMP = '.last-update-check';

    private const string FOREIGN_JSON = '{"require":{"acme/real-work":"^1.0"}}';

    private const string FOREIGN_LOCK = '{"packages":[{"name":"acme/real-work"}]}';

    private const int TIMEOUT_SECONDS = 30;

    private string $directory;

    private string $install;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/kinetis-mcp-docs-setup-' . bin2hex(random_bytes(8));
        $this->install = $this->directory . '/install';

        mkdir($this->directory . '/stubs', 0o755, true);
        mkdir($this->directory . '/home', 0o755, true);

        // `docker info` is the prerequisite check and answers on its
        // own. Otherwise docker's own arguments end at the image name,
        // and what follows is the command the container runs — recorded
        // on one line, then run in the install directory the mount
        // stands for.
        $this->stub('docker', <<<'SH'
            case "$1" in
                info) exit 0 ;;
            esac

            while [ $# -gt 0 ] && [ "$1" != composer:2 ]; do shift; done
            [ $# -gt 0 ] || exit 1
            shift

            printf '%s\n' "$(printf '%s' "$*" | tr '\n' ' ')" >> "$CONTAINERS"
            cd "$KINETIS_MCP_DOCS_DIR" || exit 1
            exec "$@"
            SH);
        $this->stub('composer', self::COMPOSER_STUB);
        // What start.sh execs once its update check is done.
        $this->stub('php', <<<'SH'
            echo "server $1" >> "$EVENTS"
            cat >/dev/null
            printf '%s\n' '{"result":{"serverInfo":{}}}' '{"result":{"resources":[]}}' '{"result":{"contents":[]}}'
            SH);
        $this->stub('claude', <<<'SH'
            echo "claude $2" >> "$EVENTS"
            [ "$2" = add ] && printf '%s\n' "$*" > "$REGISTERED"
            exit 0
            SH);
    }

    protected function tearDown(): void
    {
        $entries = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        /** @var SplFileInfo $entry */
        foreach ($entries as $entry) {
            $entry->isDir() && !$entry->isLink()
                ? rmdir($entry->getPathname())
                : unlink($entry->getPathname());
        }

        rmdir($this->directory);
    }

    public function test_an_empty_directory_is_installed_into_and_left_carrying_the_marker(): void
    {
        mkdir($this->install, 0o755, true);

        self::assertSame(0, $this->runSetup());
        self::assertFalse(is_link($this->install . '/' . self::MARKER));
        self::assertSame(self::MARKER_TEXT, file_get_contents($this->install . '/' . self::MARKER));
        self::assertStringContainsString('kinetis/mcp-docs', (string) file_get_contents($this->install . '/composer.json'));
        self::assertSame(
            ['composer install', self::SERVER_LINE, 'claude remove', 'claude add'],
            $this->events(),
        );
    }

    public function test_a_directory_carrying_the_marker_this_script_wrote_is_reused(): void
    {
        mkdir($this->install, 0o755, true);

        self::assertSame(0, $this->runSetup());

        file_put_contents($this->install . '/composer.json', self::FOREIGN_JSON);
        file_put_contents($this->install . '/composer.lock', self::FOREIGN_LOCK);

        self::assertSame(0, $this->runSetup());
        self::assertFileDoesNotExist($this->install . '/composer.lock');
        self::assertStringContainsString('kinetis/mcp-docs', (string) file_get_contents($this->install . '/composer.json'));
    }

    public function test_the_handshake_runs_the_launcher_that_gets_registered(): void
    {
        mkdir($this->install, 0o755, true);

        self::assertSame(0, $this->runSetup());

        // Two containers: the install, then the handshake — and what
        // the handshake ran is the whole of what gets registered, past
        // the image name.
        $containers = $this->containerCommands();

        self::assertCount(2, $containers);
        self::assertStringStartsWith('sh -c ', $containers[0]);
        self::assertSame('sh ' . self::LAUNCHER, $containers[1]);
        self::assertStringEndsWith('composer:2 ' . $containers[1], $this->registeredCommand());
        self::assertContains(self::SERVER_LINE, $this->events());
    }

    public function test_an_install_that_succeeded_stamps_the_time_and_spares_the_handshake_an_update(): void
    {
        mkdir($this->install, 0o755, true);
        $before = time();

        self::assertSame(0, $this->runSetup());

        // The install is the only Composer run here, so the stamp is
        // its own and the handshake read it rather than updating.
        self::assertNotContains('composer update', $this->events());

        $stamp = (int) file_get_contents($this->install . '/' . self::STAMP);

        self::assertGreaterThanOrEqual($before, $stamp);
        self::assertLessThanOrEqual(time(), $stamp);
    }

    public function test_an_install_that_failed_leaves_the_timestamp_where_it_was(): void
    {
        $this->seedOurDirectory();
        file_put_contents($this->install . '/' . self::STAMP, "0\n");

        $this->stub('composer', 'echo "composer $1" >> "$EVENTS"; exit 1');

        self::assertSame(1, $this->runSetup());
        self::assertStringContainsString('composer install failed', $this->stderr());
        self::assertSame("0\n", file_get_contents($this->install . '/' . self::STAMP));
        self::assertSame(['composer install'], $this->events());
    }

    public function test_a_spawn_arriving_during_the_install_waits_for_it_and_does_not_update(): void
    {
        $this->seedOurDirectory();

        $this->stub('composer', <<<'SH'
            echo "composer $1 start" >> "$EVENTS"
            sleep 1
            mkdir -p vendor/kinetis/mcp-docs
            cp "$LAUNCHER_SOURCE" vendor/kinetis/mcp-docs/start.sh
            echo "composer $1 end" >> "$EVENTS"
            SH);

        $setup = $this->launchSetup();
        $this->awaitEvent('composer install start');
        $spawn = $this->launchLauncher();

        self::assertSame([0, 0], $this->await([$setup, $spawn]));

        // Nothing runs against the directory until the install is done,
        // and the spawn that waited reads the timestamp it wrote rather
        // than updating behind it. The two servers land in whichever
        // order the kernel hands the lock on, so only the head is
        // ordered.
        $events = $this->events();

        self::assertSame(['composer install start', 'composer install end'], array_slice($events, 0, 2));
        self::assertSame(
            ['claude add', 'claude remove', self::SERVER_LINE, self::SERVER_LINE],
            self::sorted(array_slice($events, 2)),
        );
    }

    public function test_a_non_empty_directory_carrying_no_marker_is_refused(): void
    {
        $this->seedForeignDirectory();

        self::assertSame(1, $this->runSetup());
        self::assertStringContainsString('carries no marker written by this script', $this->stderr());
        $this->assertForeignDirectoryUntouched();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function nonMatchingMarkers(): iterable
    {
        yield 'unrelated content' => ["a scratch directory of mine\n"];
        yield 'the line with another appended' => [self::MARKER_TEXT . "and something else\n"];
        yield 'the line without its newline' => [rtrim(self::MARKER_TEXT, "\n")];
        yield 'empty' => [''];
    }

    #[DataProvider('nonMatchingMarkers')]
    public function test_a_marker_holding_anything_but_the_exact_line_is_refused(string $content): void
    {
        $this->seedForeignDirectory();
        file_put_contents($this->install . '/' . self::MARKER, $content);

        self::assertSame(1, $this->runSetup());
        $this->assertForeignDirectoryUntouched();
        self::assertSame($content, file_get_contents($this->install . '/' . self::MARKER));
    }

    public function test_a_symlink_marker_is_refused_and_not_followed(): void
    {
        $this->seedForeignDirectory();

        $target = $this->directory . '/elsewhere';
        file_put_contents($target, self::MARKER_TEXT);
        symlink($target, $this->install . '/' . self::MARKER);

        self::assertSame(1, $this->runSetup());
        $this->assertForeignDirectoryUntouched();
        self::assertTrue(is_link($this->install . '/' . self::MARKER));
        self::assertSame(self::MARKER_TEXT, file_get_contents($target));
    }

    public function test_a_directory_under_the_marker_name_is_refused(): void
    {
        $this->seedForeignDirectory();
        mkdir($this->install . '/' . self::MARKER, 0o755);

        self::assertSame(1, $this->runSetup());
        $this->assertForeignDirectoryUntouched();
        self::assertDirectoryExists($this->install . '/' . self::MARKER);
    }

    /** An install directory holding somebody else's Composer project. */
    private function seedForeignDirectory(): void
    {
        mkdir($this->install, 0o755, true);

        file_put_contents($this->install . '/composer.json', self::FOREIGN_JSON);
        file_put_contents($this->install . '/composer.lock', self::FOREIGN_LOCK);
    }

    /** An install directory a previous run of this script already owns. */
    private function seedOurDirectory(): void
    {
        mkdir($this->install, 0o755, true);

        file_put_contents($this->install . '/' . self::MARKER, self::MARKER_TEXT);
    }

    private function assertForeignDirectoryUntouched(): void
    {
        self::assertSame(self::FOREIGN_JSON, file_get_contents($this->install . '/composer.json'));
        self::assertSame(self::FOREIGN_LOCK, file_get_contents($this->install . '/composer.lock'));
        self::assertSame([], $this->events());
    }

    private function stub(string $name, string $body): void
    {
        $path = $this->directory . '/stubs/' . $name;

        file_put_contents($path, "#!/bin/sh\n" . $body . "\n");
        chmod($path, 0o755);
    }

    private function runSetup(): int
    {
        return $this->await([$this->launchSetup()])[0];
    }

    /**
     * @return resource
     */
    private function launchSetup(): mixed
    {
        return $this->launch(['bash', self::SCRIPT], $this->directory);
    }

    /**
     * A client session spawning the registered launcher against the
     * same install directory.
     *
     * @return resource
     */
    private function launchLauncher(): mixed
    {
        return $this->launch(['sh', self::LAUNCHER_SOURCE], $this->install);
    }

    /**
     * @param list<string> $command
     *
     * @return resource
     */
    private function launch(array $command, string $workingDirectory): mixed
    {
        $process = proc_open(
            $command,
            [
                ['file', '/dev/null', 'r'],
                ['file', $this->directory . '/stdout', 'a'],
                ['file', $this->directory . '/stderr', 'a'],
            ],
            $pipes,
            $workingDirectory,
            [
                'PATH' => $this->directory . '/stubs:' . (getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin'),
                'HOME' => $this->directory . '/home',
                'KINETIS_MCP_DOCS_DIR' => $this->install,
                'EVENTS' => $this->directory . '/events',
                'CONTAINERS' => $this->directory . '/containers',
                'REGISTERED' => $this->directory . '/registered',
                'LAUNCHER_SOURCE' => self::LAUNCHER_SOURCE,
            ],
        );

        self::assertIsResource($process);

        return $process;
    }

    /**
     * @param list<resource> $processes
     *
     * @return list<int> one exit status per process, in launch order
     */
    private function await(array $processes): array
    {
        $deadline = microtime(true) + self::TIMEOUT_SECONDS;
        $statuses = [];

        foreach ($processes as $process) {
            $status = proc_get_status($process);

            while ($status['running'] === true) {
                if (microtime(true) > $deadline) {
                    proc_terminate($process, 9);
                    proc_close($process);

                    self::fail('A process did not finish within ' . self::TIMEOUT_SECONDS . ' seconds.');
                }

                usleep(10_000);
                $status = proc_get_status($process);
            }

            proc_close($process);
            $statuses[] = $status['exitcode'];
        }

        return $statuses;
    }

    private function awaitEvent(string $event): void
    {
        $deadline = microtime(true) + self::TIMEOUT_SECONDS;

        while (!in_array($event, $this->events(), true)) {
            if (microtime(true) > $deadline) {
                self::fail('"' . $event . '" was not reached within ' . self::TIMEOUT_SECONDS . ' seconds.');
            }

            usleep(10_000);
        }
    }

    private function stderr(): string
    {
        return (string) file_get_contents($this->directory . '/stderr');
    }

    private function registeredCommand(): string
    {
        return rtrim((string) file_get_contents($this->directory . '/registered'), "\n");
    }

    /**
     * One entry per container the script ran, in order: the command
     * left once docker's own arguments and the image name are gone.
     *
     * @return list<string>
     */
    private function containerCommands(): array
    {
        return $this->lines($this->directory . '/containers');
    }

    /**
     * @return list<string>
     */
    private function events(): array
    {
        return $this->lines($this->directory . '/events');
    }

    /**
     * @return list<string>
     */
    private function lines(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }

        $contents = rtrim((string) file_get_contents($path), "\n");

        return $contents === '' ? [] : explode("\n", $contents);
    }

    /**
     * @param list<string> $events
     *
     * @return list<string>
     */
    private static function sorted(array $events): array
    {
        sort($events);

        return $events;
    }
}
