<?php

declare(strict_types=1);

namespace Kinetis\Storage\Tests;

use Kinetis\Storage\AmpFileAdapter;
use League\Flysystem\Config;
use League\Flysystem\DirectoryAttributes;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\Visibility;
use PHPUnit\Framework\TestCase;

use function Amp\File\filesystem;

final class AmpFileAdapterTest extends TestCase
{
    private string $root;

    private AmpFileAdapter $adapter;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/kinetis-storage-test-' . bin2hex(random_bytes(8));
        mkdir($this->root, 0777, true);
        $this->adapter = new AmpFileAdapter(filesystem(), $this->root);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $entryPath = "{$path}/{$entry}";

            if (is_dir($entryPath)) {
                $this->removeDirectory($entryPath);
            } else {
                unlink($entryPath);
            }
        }

        rmdir($path);
    }

    public function test_write_then_read_round_trips(): void
    {
        $this->adapter->write('greeting.txt', 'hello world', new Config());

        self::assertSame('hello world', $this->adapter->read('greeting.txt'));
    }

    public function test_file_exists_reflects_real_state(): void
    {
        self::assertFalse($this->adapter->fileExists('nothing.txt'));

        $this->adapter->write('nothing.txt', 'now it exists', new Config());

        self::assertTrue($this->adapter->fileExists('nothing.txt'));
    }

    public function test_write_creates_missing_parent_directories(): void
    {
        $this->adapter->write('nested/deep/file.txt', 'contents', new Config());

        self::assertTrue($this->adapter->fileExists('nested/deep/file.txt'));
        self::assertTrue($this->adapter->directoryExists('nested/deep'));
    }

    public function test_delete_removes_the_file(): void
    {
        $this->adapter->write('to-delete.txt', 'x', new Config());
        $this->adapter->delete('to-delete.txt');

        self::assertFalse($this->adapter->fileExists('to-delete.txt'));
    }

    public function test_reading_a_missing_file_throws(): void
    {
        $this->expectException(UnableToReadFile::class);
        $this->adapter->read('missing.txt');
    }

    public function test_create_directory_then_delete_directory_recursively(): void
    {
        $this->adapter->createDirectory('a/b/c', new Config());
        $this->adapter->write('a/b/c/file.txt', 'x', new Config());

        self::assertTrue($this->adapter->directoryExists('a/b/c'));

        $this->adapter->deleteDirectory('a');

        self::assertFalse($this->adapter->directoryExists('a'));
        self::assertFalse($this->adapter->fileExists('a/b/c/file.txt'));
    }

    public function test_move_relocates_the_file(): void
    {
        $this->adapter->write('source.txt', 'moved contents', new Config());
        $this->adapter->move('source.txt', 'destination.txt', new Config());

        self::assertFalse($this->adapter->fileExists('source.txt'));
        self::assertSame('moved contents', $this->adapter->read('destination.txt'));
    }

    public function test_copy_duplicates_the_file_leaving_the_source_intact(): void
    {
        $this->adapter->write('original.txt', 'copied contents', new Config());
        $this->adapter->copy('original.txt', 'duplicate.txt', new Config());

        self::assertSame('copied contents', $this->adapter->read('original.txt'));
        self::assertSame('copied contents', $this->adapter->read('duplicate.txt'));
    }

    public function test_write_stream_then_read_stream_round_trips(): void
    {
        $source = fopen('php://temp', 'r+b');
        fwrite($source, 'streamed contents');
        rewind($source);

        $this->adapter->writeStream('streamed.txt', $source, new Config());

        $result = $this->adapter->readStream('streamed.txt');

        self::assertSame('streamed contents', stream_get_contents($result));
    }

    public function test_file_size_and_last_modified_reflect_real_metadata(): void
    {
        $this->adapter->write('sized.txt', '12345', new Config());

        self::assertSame(5, $this->adapter->fileSize('sized.txt')->fileSize());
        self::assertIsInt($this->adapter->lastModified('sized.txt')->lastModified());
    }

    public function test_mime_type_is_detected_from_content(): void
    {
        $this->adapter->write('document.json', '{"key": "value"}', new Config());

        self::assertSame('application/json', $this->adapter->mimeType('document.json')->mimeType());
    }

    public function test_set_visibility_then_visibility_round_trips(): void
    {
        $this->adapter->write('secret.txt', 'x', new Config());

        $this->adapter->setVisibility('secret.txt', Visibility::PRIVATE);
        self::assertSame(Visibility::PRIVATE, $this->adapter->visibility('secret.txt')->visibility());

        $this->adapter->setVisibility('secret.txt', Visibility::PUBLIC);
        self::assertSame(Visibility::PUBLIC, $this->adapter->visibility('secret.txt')->visibility());
    }

    public function test_list_contents_shallow_does_not_descend_into_subdirectories(): void
    {
        $this->adapter->write('top.txt', 'x', new Config());
        $this->adapter->write('sub/nested.txt', 'x', new Config());

        $paths = array_map(static fn ($attrs) => $attrs->path(), iterator_to_array($this->adapter->listContents('', false)));

        self::assertContains('top.txt', $paths);
        self::assertContains('sub', $paths);
        self::assertNotContains('sub/nested.txt', $paths);
    }

    public function test_list_contents_deep_descends_into_subdirectories(): void
    {
        $this->adapter->write('top.txt', 'x', new Config());
        $this->adapter->write('sub/nested.txt', 'x', new Config());

        $paths = array_map(static fn ($attrs) => $attrs->path(), iterator_to_array($this->adapter->listContents('', true)));

        self::assertContains('sub/nested.txt', $paths);
    }

    public function test_list_contents_distinguishes_files_from_directories(): void
    {
        $this->adapter->write('file.txt', 'x', new Config());
        $this->adapter->createDirectory('directory', new Config());

        $entries = iterator_to_array($this->adapter->listContents('', false));
        $byPath = [];

        foreach ($entries as $entry) {
            $byPath[$entry->path()] = $entry;
        }

        self::assertTrue($byPath['file.txt']->isFile());
        self::assertInstanceOf(DirectoryAttributes::class, $byPath['directory']);
    }
}
