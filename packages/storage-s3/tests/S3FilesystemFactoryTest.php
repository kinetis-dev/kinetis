<?php

declare(strict_types=1);

namespace Kinetis\StorageS3\Tests;

use AsyncAws\Core\AbstractApi;
use AsyncAws\Core\Configuration;
use Kinetis\Config\Config;
use Kinetis\Config\Exception\MissingConfigException;
use Kinetis\StorageS3\Exception\S3ConfigurationException;
use Kinetis\StorageS3\S3Client;
use Kinetis\StorageS3\S3FilesystemFactory;
use League\Flysystem\AsyncAwsS3\AsyncAwsS3Adapter;
use League\Flysystem\Filesystem;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Symfony\Component\HttpClient\AmpHttpClient;

final class S3FilesystemFactoryTest extends TestCase
{
    public function test_builds_a_filesystem_backed_by_the_async_aws_s3_adapter(): void
    {
        $filesystem = S3FilesystemFactory::fromConfig(self::config());

        self::assertInstanceOf(Filesystem::class, $filesystem);
        self::assertInstanceOf(AsyncAwsS3Adapter::class, self::adapterOf($filesystem));
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

        self::assertSame('default-bucket', self::bucketOf(self::adapterOf($default)));
        self::assertSame('backups-bucket', self::bucketOf(self::adapterOf($backups)));
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

    public function test_no_endpoint_leaves_async_aws_its_regional_endpoint_table(): void
    {
        $configuration = self::configurationOf(S3FilesystemFactory::fromConfig(self::config()));

        self::assertTrue($configuration->isDefault(Configuration::OPTION_ENDPOINT));
        self::assertSame('false', $configuration->get(Configuration::OPTION_PATH_STYLE_ENDPOINT));
    }

    public function test_an_ambient_endpoint_url_is_refused_rather_than_silently_used(): void
    {
        $restore = $_ENV['AWS_ENDPOINT_URL'] ?? null;
        $_ENV['AWS_ENDPOINT_URL'] = 'https://s3.somewhere-else.test';

        try {
            $this->expectException(S3ConfigurationException::class);
            $this->expectExceptionMessage('FILESYSTEM_S3_ENDPOINT');
            S3FilesystemFactory::fromConfig(self::config());
        } finally {
            if ($restore === null) {
                unset($_ENV['AWS_ENDPOINT_URL']);
            } else {
                $_ENV['AWS_ENDPOINT_URL'] = $restore;
            }
        }
    }

    public function test_an_explicit_endpoint_is_reduced_to_its_origin_and_addressed_path_style(): void
    {
        $configuration = self::configurationOf(S3FilesystemFactory::fromConfig(
            self::config(['FILESYSTEM_S3_ENDPOINT' => 'HTTPS://Storage.example.test:9000/']),
        ));

        self::assertSame('https://storage.example.test:9000', $configuration->get(Configuration::OPTION_ENDPOINT));
        self::assertSame('true', $configuration->get(Configuration::OPTION_PATH_STYLE_ENDPOINT));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unusableEndpoints(): iterable
    {
        yield 'no scheme' => ['s3.example.test'];
        yield 'unsupported scheme' => ['ftp://s3.example.test'];
        yield 'no host' => ['https:///bucket'];
        yield 'userinfo' => ['https://key:secret@s3.example.test'];
        yield 'path' => ['https://s3.example.test/bucket'];
        yield 'query' => ['https://s3.example.test?region=us-east-1'];
        yield 'fragment' => ['https://s3.example.test#frag'];
    }

    #[DataProvider('unusableEndpoints')]
    public function test_an_endpoint_that_is_not_one_origin_is_refused(string $endpoint): void
    {
        $this->expectException(S3ConfigurationException::class);
        S3FilesystemFactory::fromConfig(self::config(['FILESYSTEM_S3_ENDPOINT' => $endpoint]));
    }

    public function test_a_plain_http_endpoint_needs_its_own_opt_in(): void
    {
        $this->expectException(S3ConfigurationException::class);
        $this->expectExceptionMessage('FILESYSTEM_S3_PLAINTEXT');
        S3FilesystemFactory::fromConfig(self::config(['FILESYSTEM_S3_ENDPOINT' => 'http://minio:9000']));
    }

    public function test_the_plaintext_opt_in_accepts_a_compose_service_name(): void
    {
        $configuration = self::configurationOf(S3FilesystemFactory::fromConfig(self::config([
            'FILESYSTEM_S3_ENDPOINT' => 'http://minio:9000',
            'FILESYSTEM_S3_PLAINTEXT' => 'true',
        ])));

        self::assertSame('http://minio:9000', $configuration->get(Configuration::OPTION_ENDPOINT));
    }

    public function test_every_request_gets_the_default_timeout_and_follows_no_redirect(): void
    {
        $options = self::transportOptionsOf(S3FilesystemFactory::fromConfig(self::config()));

        self::assertSame(60.0, $options['timeout']);
        self::assertSame(60.0, $options['max_duration']);
        self::assertSame(0, $options['max_redirects']);
    }

    public function test_a_configured_timeout_covers_connect_idle_and_transfer_alike(): void
    {
        $options = self::transportOptionsOf(S3FilesystemFactory::fromConfig(
            self::config(['FILESYSTEM_S3_TIMEOUT' => '2.5']),
        ));

        self::assertSame(2.5, $options['timeout']);
        self::assertSame(2.5, $options['max_duration']);
    }

    public function test_a_non_positive_timeout_is_refused(): void
    {
        $this->expectException(S3ConfigurationException::class);
        $this->expectExceptionMessage('FILESYSTEM_S3_TIMEOUT');
        S3FilesystemFactory::fromConfig(self::config(['FILESYSTEM_S3_TIMEOUT' => '-1']));
    }

    /**
     * @param array<string, string> $extra
     */
    private static function config(array $extra = []): Config
    {
        return new Config([
            'FILESYSTEM_S3_BUCKET' => 'default-bucket',
            'FILESYSTEM_S3_REGION' => 'us-east-1',
            ...$extra,
        ]);
    }

    private static function adapterOf(Filesystem $filesystem): AsyncAwsS3Adapter
    {
        $adapter = new ReflectionProperty(Filesystem::class, 'adapter')->getValue($filesystem);

        self::assertInstanceOf(AsyncAwsS3Adapter::class, $adapter);

        return $adapter;
    }

    private static function bucketOf(AsyncAwsS3Adapter $adapter): string
    {
        /** @var string */
        return new ReflectionProperty(AsyncAwsS3Adapter::class, 'bucket')->getValue($adapter);
    }

    private static function clientOf(Filesystem $filesystem): S3Client
    {
        $client = new ReflectionProperty(AsyncAwsS3Adapter::class, 'client')->getValue(self::adapterOf($filesystem));

        self::assertInstanceOf(S3Client::class, $client);

        return $client;
    }

    private static function configurationOf(Filesystem $filesystem): Configuration
    {
        return self::clientOf($filesystem)->getConfiguration();
    }

    /**
     * @return array<string, mixed>
     */
    private static function transportOptionsOf(Filesystem $filesystem): array
    {
        $transport = new ReflectionProperty(AbstractApi::class, 'httpClient')->getValue(self::clientOf($filesystem));

        self::assertInstanceOf(AmpHttpClient::class, $transport);

        /** @var array<string, mixed> */
        return new ReflectionProperty(AmpHttpClient::class, 'defaultOptions')->getValue($transport);
    }
}
