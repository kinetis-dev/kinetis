<?php

declare(strict_types=1);

namespace Kinetis\Storage;

use Amp\ByteStream\ReadableResourceStream;
use Amp\ByteStream\StreamException;
use Amp\File\Filesystem;
use Amp\File\FilesystemException;
use League\Flysystem\Config;
use League\Flysystem\DirectoryAttributes;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\PathPrefixer;
use League\Flysystem\StorageAttributes;
use League\Flysystem\UnableToCopyFile;
use League\Flysystem\UnableToCreateDirectory;
use League\Flysystem\UnableToDeleteDirectory;
use League\Flysystem\UnableToDeleteFile;
use League\Flysystem\UnableToMoveFile;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToRetrieveMetadata;
use League\Flysystem\UnableToSetVisibility;
use League\Flysystem\UnableToWriteFile;
use League\Flysystem\UnixVisibility\PortableVisibilityConverter;
use League\Flysystem\UnixVisibility\VisibilityConverter;
use League\MimeTypeDetection\FinfoMimeTypeDetector;
use League\MimeTypeDetection\MimeTypeDetector;

use function Amp\ByteStream\pipe;

/**
 * A League\Flysystem\FilesystemAdapter for local disk backed by
 * Amp\File\Filesystem instead of Flysystem's own (blocking) local adapter
 * — every method here delegates to amphp/file, whose calls suspend the
 * calling Fiber via Revolt rather than blocking the whole worker process,
 * the same non-blocking idiom Amp\Mysql/Amp\Redis already use throughout
 * Kinetis\Persistence. Flysystem's own FilesystemAdapter interface has
 * plain synchronous-looking method signatures throughout (no Future/
 * promise return types anywhere) — that's not a mismatch, since
 * Amp\File\Filesystem's methods look synchronous too while suspending
 * internally; the Fiber suspends, not the method signature.
 *
 * readStream() is the one real, disclosed exception to "genuinely
 * non-blocking throughout": PHP resources are an engine-level type that
 * can't be backed by arbitrary userland code without a registered stream
 * wrapper, so there's no way to hand back a resource that lazily pulls
 * from Amp\File\File on demand. It reads the whole file via the
 * non-blocking read() below, then buffers that into an in-memory
 * `php://temp` resource for the caller — the disk read itself never
 * blocks, but the whole file is loaded into memory up front rather than
 * streamed incrementally. write()/writeStream()/copy() are genuinely
 * streaming via Amp\ByteStream\pipe() between real Amp\File\File handles,
 * with no such compromise.
 */
final readonly class AmpFileAdapter implements FilesystemAdapter
{
    private const int MIME_TYPE_SAMPLE_BYTES = 4096;

    private PathPrefixer $prefixer;

    private VisibilityConverter $visibility;

    private MimeTypeDetector $mimeTypeDetector;

    public function __construct(
        private Filesystem $filesystem,
        string $root,
        ?VisibilityConverter $visibility = null,
        ?MimeTypeDetector $mimeTypeDetector = null,
    ) {
        $this->prefixer = new PathPrefixer($root);
        $this->visibility = $visibility ?? new PortableVisibilityConverter();
        $this->mimeTypeDetector = $mimeTypeDetector ?? new FinfoMimeTypeDetector();
    }

    #[\Override]
    public function fileExists(string $path): bool
    {
        return $this->filesystem->isFile($this->prefixer->prefixPath($path));
    }

    #[\Override]
    public function directoryExists(string $path): bool
    {
        return $this->filesystem->isDirectory($this->prefixer->prefixDirectoryPath($path));
    }

    #[\Override]
    public function write(string $path, string $contents, Config $config): void
    {
        $location = $this->prefixer->prefixPath($path);

        try {
            $this->ensureParentDirectoryExists($location, $config);
            $this->filesystem->write($location, $contents);
        } catch (FilesystemException $e) {
            throw UnableToWriteFile::atLocation($path, $e->getMessage(), $e);
        }

        $this->applyFileVisibility($location, $config);
    }

    #[\Override]
    public function writeStream(string $path, $contents, Config $config): void
    {
        $location = $this->prefixer->prefixPath($path);

        try {
            $this->ensureParentDirectoryExists($location, $config);
            $handle = $this->filesystem->openFile($location, 'w');

            try {
                pipe(new ReadableResourceStream($contents), $handle);
            } finally {
                $handle->close();
            }
        } catch (FilesystemException|StreamException $e) {
            throw UnableToWriteFile::atLocation($path, $e->getMessage(), $e);
        }

        $this->applyFileVisibility($location, $config);
    }

    #[\Override]
    public function read(string $path): string
    {
        try {
            return $this->filesystem->read($this->prefixer->prefixPath($path));
        } catch (FilesystemException $e) {
            throw UnableToReadFile::fromLocation($path, $e->getMessage(), $e);
        }
    }

    #[\Override]
    public function readStream(string $path)
    {
        $contents = $this->read($path);

        /** @var resource $stream */
        $stream = fopen('php://temp', 'r+b');
        fwrite($stream, $contents);
        rewind($stream);

        return $stream;
    }

    #[\Override]
    public function delete(string $path): void
    {
        try {
            $this->filesystem->deleteFile($this->prefixer->prefixPath($path));
        } catch (FilesystemException $e) {
            throw UnableToDeleteFile::atLocation($path, $e->getMessage(), $e);
        }
    }

    #[\Override]
    public function deleteDirectory(string $path): void
    {
        $location = $this->prefixer->prefixDirectoryPath($path);

        try {
            $this->deleteDirectoryRecursively($location);
        } catch (FilesystemException $e) {
            throw UnableToDeleteDirectory::atLocation($path, $e->getMessage(), $e);
        }
    }

    #[\Override]
    public function createDirectory(string $path, Config $config): void
    {
        $location = $this->prefixer->prefixDirectoryPath($path);

        try {
            $this->filesystem->createDirectoryRecursively($location, $this->directoryModeFor($config));
        } catch (FilesystemException $e) {
            throw UnableToCreateDirectory::atLocation($path, $e->getMessage(), $e);
        }
    }

    #[\Override]
    public function setVisibility(string $path, string $visibility): void
    {
        $location = $this->prefixer->prefixPath($path);

        try {
            $mode = $this->filesystem->isDirectory($location)
                ? $this->visibility->forDirectory($visibility)
                : $this->visibility->forFile($visibility);
            $this->filesystem->changePermissions($location, $mode);
        } catch (FilesystemException $e) {
            throw UnableToSetVisibility::atLocation($path, $e->getMessage(), $e);
        }
    }

    #[\Override]
    public function visibility(string $path): FileAttributes
    {
        $location = $this->prefixer->prefixPath($path);
        $status = $this->filesystem->getStatus($location);

        if ($status === null) {
            throw UnableToRetrieveMetadata::visibility($path, 'path does not exist');
        }

        return new FileAttributes($path, visibility: $this->visibility->inverseForFile($status['mode'] & 0777));
    }

    #[\Override]
    public function mimeType(string $path): FileAttributes
    {
        $location = $this->prefixer->prefixPath($path);

        try {
            $handle = $this->filesystem->openFile($location, 'r');

            try {
                $sample = $handle->read(length: self::MIME_TYPE_SAMPLE_BYTES) ?? '';
            } finally {
                $handle->close();
            }
        } catch (FilesystemException $e) {
            throw UnableToRetrieveMetadata::mimeType($path, $e->getMessage(), $e);
        }

        $mimeType = $this->mimeTypeDetector->detectMimeType($path, $sample);

        if ($mimeType === null) {
            throw UnableToRetrieveMetadata::mimeType($path, 'unable to determine mime type');
        }

        return new FileAttributes($path, mimeType: $mimeType);
    }

    #[\Override]
    public function lastModified(string $path): FileAttributes
    {
        try {
            $time = $this->filesystem->getModificationTime($this->prefixer->prefixPath($path));
        } catch (FilesystemException $e) {
            throw UnableToRetrieveMetadata::lastModified($path, $e->getMessage(), $e);
        }

        return new FileAttributes($path, lastModified: $time);
    }

    #[\Override]
    public function fileSize(string $path): FileAttributes
    {
        try {
            $size = $this->filesystem->getSize($this->prefixer->prefixPath($path));
        } catch (FilesystemException $e) {
            throw UnableToRetrieveMetadata::fileSize($path, $e->getMessage(), $e);
        }

        return new FileAttributes($path, fileSize: $size);
    }

    #[\Override]
    public function listContents(string $path, bool $deep): iterable
    {
        $location = $this->prefixer->prefixDirectoryPath($path);

        if (!$this->filesystem->isDirectory($location)) {
            return;
        }

        yield from $this->listContentsRecursively($location, $deep);
    }

    #[\Override]
    public function move(string $source, string $destination, Config $config): void
    {
        $from = $this->prefixer->prefixPath($source);
        $to = $this->prefixer->prefixPath($destination);

        try {
            $this->ensureParentDirectoryExists($to, $config);
            $this->filesystem->move($from, $to);
        } catch (FilesystemException $e) {
            throw UnableToMoveFile::fromLocationTo($source, $destination, $e);
        }
    }

    #[\Override]
    public function copy(string $source, string $destination, Config $config): void
    {
        $from = $this->prefixer->prefixPath($source);
        $to = $this->prefixer->prefixPath($destination);

        try {
            $this->ensureParentDirectoryExists($to, $config);
            $readHandle = $this->filesystem->openFile($from, 'r');

            try {
                $writeHandle = $this->filesystem->openFile($to, 'w');

                try {
                    pipe($readHandle, $writeHandle);
                } finally {
                    $writeHandle->close();
                }
            } finally {
                $readHandle->close();
            }
        } catch (FilesystemException|StreamException $e) {
            throw UnableToCopyFile::fromLocationTo($source, $destination, $e);
        }
    }

    /**
     * @return iterable<StorageAttributes>
     */
    private function listContentsRecursively(string $location, bool $deep): iterable
    {
        foreach ($this->filesystem->listFiles($location) as $name) {
            $entryLocation = $location . '/' . $name;
            $publicPath = $this->prefixer->stripPrefix($entryLocation);

            if ($this->filesystem->isDirectory($entryLocation)) {
                yield new DirectoryAttributes($publicPath);

                if ($deep) {
                    yield from $this->listContentsRecursively($entryLocation, true);
                }

                continue;
            }

            $status = $this->filesystem->getStatus($entryLocation);
            yield new FileAttributes(
                $publicPath,
                fileSize: $status['size'] ?? null,
                lastModified: $status['mtime'] ?? null,
            );
        }
    }

    private function deleteDirectoryRecursively(string $location): void
    {
        if (!$this->filesystem->isDirectory($location)) {
            return;
        }

        foreach ($this->filesystem->listFiles($location) as $name) {
            $entryLocation = $location . '/' . $name;

            if ($this->filesystem->isDirectory($entryLocation)) {
                $this->deleteDirectoryRecursively($entryLocation);
            } else {
                $this->filesystem->deleteFile($entryLocation);
            }
        }

        $this->filesystem->deleteDirectory($location);
    }

    private function ensureParentDirectoryExists(string $location, Config $config): void
    {
        $directory = dirname($location);

        if ($directory === '.' || $this->filesystem->isDirectory($directory)) {
            return;
        }

        $this->filesystem->createDirectoryRecursively($directory, $this->directoryModeFor($config));
    }

    private function directoryModeFor(Config $config): int
    {
        $visibility = $config->get(Config::OPTION_DIRECTORY_VISIBILITY, $config->get(Config::OPTION_VISIBILITY));

        return $visibility !== null
            ? $this->visibility->forDirectory($visibility)
            : $this->visibility->defaultForDirectories();
    }

    private function applyFileVisibility(string $location, Config $config): void
    {
        $visibility = $config->get(Config::OPTION_VISIBILITY);

        if ($visibility !== null) {
            $this->filesystem->changePermissions($location, $this->visibility->forFile($visibility));
        }
    }
}
