<?php

declare(strict_types=1);

namespace Kinetis\DatabaseBridge\Tests;

use Kinetis\Cache\Exception\CacheArtifactExceptionInterface;
use Kinetis\Cache\PluginDiscovery;
use Kinetis\DatabaseBridge\OrmMetadata;
use Kinetis\DatabaseBridge\Tests\Fixtures\OrmProject\Entities\Post;
use Kinetis\DatabaseBridge\Tests\Fixtures\OrmProject\Entities\Tag;
use Kinetis\Orm\Metadata\MetadataRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OrmMetadataTest extends TestCase
{
    private const string PROJECT = __DIR__ . '/Fixtures/OrmProject';

    public function test_compile_maps_exactly_the_classes_marked_as_entities(): void
    {
        self::assertSame(
            MetadataRegistry::fromClasses([Post::class, Tag::class])->toArray(),
            OrmMetadata::compile(self::PROJECT),
        );
    }

    /**
     * The same data through a written cache file, the way a production boot
     * reads it, reconstructs the metadata the live compile describes.
     */
    public function test_a_cached_entry_reconstructs_what_the_live_compile_produced(): void
    {
        $live = OrmMetadata::compile(self::PROJECT);
        $file = (string) tempnam(sys_get_temp_dir(), 'orm-metadata');
        file_put_contents($file, '<?php return ' . var_export([OrmMetadata::class => $live], true) . ';');

        /** @var array<class-string, array<array-key, mixed>> $cached */
        $cached = require $file;
        unlink($file);

        $instance = PluginDiscovery::reconstruct($cached)[OrmMetadata::class];

        self::assertInstanceOf(OrmMetadata::class, $instance);
        self::assertSame($live, $instance->registry()->toArray());
        self::assertSame(OrmMetadata::fromArray($live)->registry()->toArray(), $instance->registry()->toArray());
    }

    /**
     * @return iterable<string, array{array<array-key, mixed>}>
     */
    public static function unusableEntries(): iterable
    {
        $post = MetadataRegistry::fromClasses([Post::class])->toArray()['entities'][0];
        $renamed = $post;
        $renamed['properties'][1]['column'] = 'headline';

        yield 'an entry compiled without kinetis/orm' => [[]];
        yield 'a malformed entry' => [['entities' => 'posts']];
        yield 'an entry for a class that no longer exists' => [['entities' => [[...$post, 'class' => 'App\\Removed']]]];
        yield 'an entry its source no longer matches' => [['entities' => [$renamed]]];
    }

    /**
     * @param array<array-key, mixed> $entry
     */
    #[DataProvider('unusableEntries')]
    public function test_a_stale_or_malformed_entry_takes_the_framework_recompile_path(array $entry): void
    {
        $this->expectException(CacheArtifactExceptionInterface::class);
        $this->expectExceptionMessage('the cache is stale or corrupt');

        PluginDiscovery::reconstruct([OrmMetadata::class => $entry]);
    }
}
