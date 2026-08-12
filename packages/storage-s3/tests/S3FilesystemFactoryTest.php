<?php

declare(strict_types=1);

namespace Kinetis\StorageS3\Tests;

use Kinetis\Config\Config;
use Kinetis\Config\Exception\MissingConfigException;
use Kinetis\StorageS3\S3FilesystemFactory;
use League\Flysystem\AsyncAwsS3\AsyncAwsS3Adapter;
use League\Flysystem\Filesystem;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

final class S3FilesystemFactoryTest extends TestCase
{
    public function test_builds_a_filesystem_backed_by_the_async_aws_s3_adapter(): void
    {
        $config = new Config([
            'FILESYSTEM_S3_BUCKET' => 'default-bucket',
            'FILESYSTEM_S3_REGION' => 'us-east-1',
        ]);

        $filesystem = S3FilesystemFactory::fromConfig($config);

        self::assertInstanceOf(Filesystem::class, $filesystem);
        self::assertInstanceOf(AsyncAwsS3Adapter::class, $this->adapterOf($filesystem));
    }

    public function test_a_named_connection_reads_its_own_bucket_not_the_defaults(): void
    {
        $config = new Config([
            'FILESYSTEM_S3_BUCKET' => 'default-bucket',
            'FILESYSTEM_S3_REGION' => 'us-east-1',
            'FILESYSTEM_BACKUPS_S3_BUCKET' => 'backups-bucket',
            'FILESYSTEM_BACKUPS_S3_REGION' => 'eu-west-1',
        ]);

        $default = S3FilesystemFactory::fromConfig($config);
        $backups = S3FilesystemFactory::fromConfig($config, 'backups');

        self::assertSame('default-bucket', $this->bucketOf($this->adapterOf($default)));
        self::assertSame('backups-bucket', $this->bucketOf($this->adapterOf($backups)));
    }

    public function test_a_missing_bucket_throws_a_clear_error(): void
    {
        $config = new Config(['FILESYSTEM_S3_REGION' => 'us-east-1']);

        $this->expectException(MissingConfigException::class);
        $this->expectExceptionMessage('FILESYSTEM_S3_BUCKET');
        S3FilesystemFactory::fromConfig($config);
    }

    public function test_a_missing_region_throws_a_clear_error(): void
    {
        $config = new Config(['FILESYSTEM_S3_BUCKET' => 'default-bucket']);

        $this->expectException(MissingConfigException::class);
        $this->expectExceptionMessage('FILESYSTEM_S3_REGION');
        S3FilesystemFactory::fromConfig($config);
    }

    public function test_a_named_connections_missing_region_names_its_own_scoped_key(): void
    {
        $config = new Config(['FILESYSTEM_BACKUPS_S3_BUCKET' => 'backups-bucket']);

        $this->expectException(MissingConfigException::class);
        $this->expectExceptionMessage('FILESYSTEM_BACKUPS_S3_REGION');
        S3FilesystemFactory::fromConfig($config, 'backups');
    }

    public function test_an_optional_endpoint_and_prefix_are_accepted_without_error(): void
    {
        $config = new Config([
            'FILESYSTEM_S3_BUCKET' => 'default-bucket',
            'FILESYSTEM_S3_REGION' => 'us-east-1',
            'FILESYSTEM_S3_ENDPOINT' => 'https://s3.example-compatible.com',
            'FILESYSTEM_S3_PREFIX' => 'app-data',
        ]);

        self::assertInstanceOf(Filesystem::class, S3FilesystemFactory::fromConfig($config));
    }

    private function adapterOf(Filesystem $filesystem): AsyncAwsS3Adapter
    {
        $property = new ReflectionProperty(Filesystem::class, 'adapter');

        /** @var AsyncAwsS3Adapter */
        return $property->getValue($filesystem);
    }

    private function bucketOf(AsyncAwsS3Adapter $adapter): string
    {
        $property = new ReflectionProperty(AsyncAwsS3Adapter::class, 'bucket');

        /** @var string */
        return $property->getValue($adapter);
    }
}
