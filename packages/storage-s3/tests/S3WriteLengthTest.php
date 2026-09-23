<?php

declare(strict_types=1);

namespace Kinetis\StorageS3\Tests;

use AsyncAws\Core\AbstractApi;
use AsyncAws\Core\Credentials\NullProvider;
use Kinetis\Config\Config;
use Kinetis\StorageS3\S3FilesystemFactory;
use League\Flysystem\AsyncAwsS3\AsyncAwsS3Adapter;
use League\Flysystem\Filesystem;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

final class S3WriteLengthTest extends TestCase
{
    private HttpPeer $peer;

    private Filesystem $filesystem;

    #[\Override]
    protected function setUp(): void
    {
        $this->peer = new HttpPeer();
        $this->filesystem = S3FilesystemFactory::fromConfig(new Config([
            'FILESYSTEM_S3_BUCKET' => 'bucket',
            'FILESYSTEM_S3_REGION' => 'us-east-1',
            'FILESYSTEM_S3_ENDPOINT' => $this->peer->endpoint(),
            'FILESYSTEM_S3_PLAINTEXT' => 'true',
            'FILESYSTEM_S3_TIMEOUT' => '5',
        ]));

        $client = new ReflectionProperty(AsyncAwsS3Adapter::class, 'client')
            ->getValue(new ReflectionProperty(Filesystem::class, 'adapter')->getValue($this->filesystem));
        new ReflectionProperty(AbstractApi::class, 'credentialProvider')->setValue($client, new NullProvider());
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->peer->close();
    }

    public function test_a_stream_write_sends_the_whole_resource_with_its_fixed_length(): void
    {
        $contents = str_repeat('0123456789', 20_000);
        $resource = fopen('php://memory', 'w+b');
        self::assertIsResource($resource);
        fwrite($resource, $contents);
        fseek($resource, 1_000);

        $this->filesystem->writeStream('stream.bin', $resource);

        [$request] = $this->peer->received();
        self::assertSame((string) strlen($contents), $request['headers']['content-length']);
        self::assertArrayNotHasKey('transfer-encoding', $request['headers']);
        self::assertSame($contents, $request['body']);
        self::assertIsResource($resource);
        fclose($resource);
    }

    public function test_a_string_write_sends_its_exact_length(): void
    {
        $this->filesystem->write('greeting.txt', 'Hello from Kinetis');

        [$request] = $this->peer->received();
        self::assertSame('18', $request['headers']['content-length']);
        self::assertArrayNotHasKey('transfer-encoding', $request['headers']);
        self::assertSame('Hello from Kinetis', $request['body']);
    }
}
