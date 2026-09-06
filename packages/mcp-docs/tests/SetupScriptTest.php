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
 * The script is driven with stand-ins for `docker` and `claude` on its
 * PATH, and with KINETIS_MCP_DOCS_DIR and HOME pointing inside a
 * temporary directory, so nothing here reaches Docker, the network, or
 * a real client's configuration.
 */
final class SetupScriptTest extends TestCase
{
    private const string SCRIPT = __DIR__ . '/../setup.sh';

    /** The one line setup.sh writes; changing it there orphans every existing install. */
    private const string MARKER_TEXT = "kinetis/mcp-docs install directory - safe for this script to rewrite\n";

    private const string MARKER = '.kinetis-mcp-docs';

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

        // `docker info` is the prerequisite check, the run carrying
        // `install` is the Composer step, and the one naming the binary
        // is the verification handshake — three responses, since the
        // notification among the four messages draws none.
        $this->stub('docker', <<<'SH'
            case " $* " in
                *" info "*) exit 0 ;;
                *" install "*) echo docker-install >> "$EVENTS"; exit 0 ;;
                *" vendor/bin/kinetis-mcp-docs "*)
                    cat >/dev/null
                    printf '%s\n' '{"result":{"serverInfo":{}}}' '{"result":{"resources":[]}}' '{"result":{"contents":[]}}'
                    exit 0 ;;
            esac
            exit 1
            SH);
        $this->stub('claude', 'echo "claude $2" >> "$EVENTS"');
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
        self::assertSame(['docker-install', 'claude remove', 'claude add'], $this->events());
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
        $process = proc_open(
            ['bash', self::SCRIPT],
            [
                ['file', '/dev/null', 'r'],
                ['file', $this->directory . '/stdout', 'a'],
                ['file', $this->directory . '/stderr', 'a'],
            ],
            $pipes,
            $this->directory,
            [
                'PATH' => $this->directory . '/stubs:' . (getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin'),
                'HOME' => $this->directory . '/home',
                'KINETIS_MCP_DOCS_DIR' => $this->install,
                'EVENTS' => $this->directory . '/events',
            ],
        );

        self::assertIsResource($process);

        $deadline = microtime(true) + self::TIMEOUT_SECONDS;
        $status = proc_get_status($process);

        while ($status['running'] === true) {
            if (microtime(true) > $deadline) {
                proc_terminate($process, 9);
                proc_close($process);

                self::fail('setup.sh did not finish within ' . self::TIMEOUT_SECONDS . ' seconds.');
            }

            usleep(10_000);
            $status = proc_get_status($process);
        }

        proc_close($process);

        return (int) $status['exitcode'];
    }

    private function stderr(): string
    {
        return (string) file_get_contents($this->directory . '/stderr');
    }

    /**
     * @return list<string>
     */
    private function events(): array
    {
        $path = $this->directory . '/events';

        if (!is_file($path)) {
            return [];
        }

        $contents = rtrim((string) file_get_contents($path), "\n");

        return $contents === '' ? [] : explode("\n", $contents);
    }
}
