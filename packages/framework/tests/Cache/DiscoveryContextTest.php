<?php

declare(strict_types=1);

namespace Kinetis\Tests\Cache;

use Kinetis\Cache\DiscoveryContext;
use Kinetis\Cache\Exception\DiscoverySectionException;
use Kinetis\Cache\PluginDiscovery;
use Kinetis\Tests\Cache\Fixtures\Sections\ConsumerSection;
use Kinetis\Tests\Cache\Fixtures\Sections\CycleASection;
use Kinetis\Tests\Cache\Fixtures\Sections\MissingClassReaderSection;
use Kinetis\Tests\Cache\Fixtures\Sections\NotASection;
use Kinetis\Tests\Cache\Fixtures\Sections\OrphanConsumerSection;
use Kinetis\Tests\Cache\Fixtures\Sections\SectionLog;
use Kinetis\Tests\Cache\Fixtures\Sections\SkippedReaderSection;
use Kinetis\Tests\Cache\Fixtures\Sections\UndeclaredSection;
use Kinetis\Tests\Cache\Fixtures\Sections\UpstreamSection;
use PHPUnit\Framework\TestCase;

final class DiscoveryContextTest extends TestCase
{
    private const string SECTIONS_ROOT = __DIR__ . '/Fixtures/SectionVendor';
    private const string FAILURES_ROOT = __DIR__ . '/Fixtures/SectionFailureVendor';
    private const string CONTROLLER = 'Kinetis\Tests\Cache\Fixtures\Http\DiscoveredPingController';

    private ?string $projectRoot = null;

    #[\Override]
    protected function setUp(): void
    {
        SectionLog::$compiled = [];
    }

    #[\Override]
    protected function tearDown(): void
    {
        if ($this->projectRoot !== null) {
            @unlink($this->projectRoot . '/src/DiscoveredPingController.php');
            @rmdir($this->projectRoot . '/src');
            @unlink($this->projectRoot . '/composer.json');
            @rmdir($this->projectRoot);
        }
    }

    public function test_a_scanned_root_is_reused_within_a_context_and_rescanned_by_a_fresh_one(): void
    {
        $root = $this->projectWithAttributedController();
        $context = new DiscoveryContext($root);

        self::assertSame([self::CONTROLLER], $context->projectClasses());

        unlink($root . '/src/DiscoveredPingController.php');

        // The same physical root again, from the same operation: the first
        // walk's result, not the filesystem as it is now.
        self::assertSame([self::CONTROLLER], $context->projectClasses());
        // A different restriction is a different physical root.
        self::assertSame([], $context->projectClasses(['Nested']));
        // The next operation sees the removal.
        self::assertSame([], new DiscoveryContext($root)->projectClasses());
    }

    public function test_a_consumer_reads_its_upstream_sections_compiled_array_once(): void
    {
        $context = new DiscoveryContext(self::SECTIONS_ROOT);

        $consumer = $context->compiled(ConsumerSection::class);

        $upstream = ['source' => 'upstream:' . self::SECTIONS_ROOT];
        self::assertSame(['upstream' => $upstream, 'repeated' => $upstream], $consumer);
        self::assertSame($upstream, $context->compiled(UpstreamSection::class));
        self::assertSame([UpstreamSection::class, ConsumerSection::class], SectionLog::$compiled);
    }

    public function test_discover_compiles_dependencies_first_and_keeps_installed_order(): void
    {
        // installed.json lists the consumer before its upstream section.
        $data = PluginDiscovery::discover(new DiscoveryContext(self::SECTIONS_ROOT));

        self::assertSame([ConsumerSection::class, UpstreamSection::class], array_keys($data));
        self::assertSame([UpstreamSection::class, ConsumerSection::class], SectionLog::$compiled);
        self::assertSame($data[UpstreamSection::class], $data[ConsumerSection::class]['upstream']);
    }

    public function test_separate_contexts_compile_separately(): void
    {
        PluginDiscovery::discover(new DiscoveryContext(self::SECTIONS_ROOT));
        PluginDiscovery::discover(new DiscoveryContext(self::SECTIONS_ROOT));

        self::assertSame(
            [UpstreamSection::class, ConsumerSection::class, UpstreamSection::class, ConsumerSection::class],
            SectionLog::$compiled,
        );
    }

    public function test_a_cycle_fails_naming_the_compile_chain(): void
    {
        $this->expectException(DiscoverySectionException::class);
        $this->expectExceptionMessage(
            CycleASection::class . ' -> ' . 'Kinetis\Tests\Cache\Fixtures\Sections\CycleBSection'
            . ' -> ' . CycleASection::class,
        );

        new DiscoveryContext(self::FAILURES_ROOT)->compiled(CycleASection::class);
    }

    public function test_a_section_no_installed_package_declares_fails_naming_requester_and_requested(): void
    {
        $this->expectException(DiscoverySectionException::class);
        $this->expectExceptionMessage(
            'Discovery section ' . UndeclaredSection::class . ', requested by ' . OrphanConsumerSection::class
            . ', is not declared by any installed package',
        );

        new DiscoveryContext(self::FAILURES_ROOT)->compiled(OrphanConsumerSection::class);
    }

    public function test_a_skipped_declaration_names_the_package_that_made_it(): void
    {
        $previous = ini_set('error_log', '/dev/null');

        try {
            new DiscoveryContext(self::FAILURES_ROOT)->compiled(SkippedReaderSection::class);
            self::fail('Reading a skipped section must fail.');
        } catch (DiscoverySectionException $e) {
            self::assertStringContainsString(NotASection::class . ', requested by ' . SkippedReaderSection::class, $e->getMessage());
            self::assertStringContainsString('Package "acme/not-a-section" declares it', $e->getMessage());
            self::assertStringContainsString('it does not implement CacheableDiscoveryInterface', $e->getMessage());
        } finally {
            ini_set('error_log', $previous === false ? '' : $previous);
        }
    }

    public function test_a_skipped_declaration_for_a_nonexistent_class_names_that_reason(): void
    {
        $previous = ini_set('error_log', '/dev/null');

        try {
            new DiscoveryContext(self::FAILURES_ROOT)->compiled(MissingClassReaderSection::class);
            self::fail('Reading a section whose declared class does not exist must fail.');
        } catch (DiscoverySectionException $e) {
            self::assertStringContainsString('Package "acme/missing-section" declares it', $e->getMessage());
            self::assertStringContainsString('no such class is autoloadable', $e->getMessage());
        } finally {
            ini_set('error_log', $previous === false ? '' : $previous);
        }
    }

    private function projectWithAttributedController(): string
    {
        $root = sys_get_temp_dir() . '/kinetis-discovery-context-' . bin2hex(random_bytes(6));
        mkdir($root . '/src', recursive: true);
        file_put_contents(
            $root . '/composer.json',
            json_encode(['autoload' => ['psr-4' => ['Kinetis\\Tests\\Cache\\Fixtures\\Http\\' => 'src/']]]),
        );
        copy(__DIR__ . '/Fixtures/Http/DiscoveredPingController.php', $root . '/src/DiscoveredPingController.php');

        return $this->projectRoot = $root;
    }
}
