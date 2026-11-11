<?php

declare(strict_types=1);

namespace Kinetis\StorageS3\Tests;

use AsyncAws\Core\AbstractApi;
use AsyncAws\Core\Configuration;
use AsyncAws\Core\Credentials\CredentialProvider;
use AsyncAws\Core\Credentials\Credentials;
use Kinetis\Config\Config;
use Kinetis\Config\Exception\MissingConfigException;
use Kinetis\StorageS3\CredentialChain;
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

    public function test_scoped_keys_sign_on_their_own_and_never_enter_the_configuration(): void
    {
        $restore = self::setAmbient([
            'AWS_ACCESS_KEY_ID' => 'ambient-id',
            'AWS_SECRET_ACCESS_KEY' => 'ambient-secret',
            'AWS_ROLE_ARN' => 'arn:aws:iam::123456789012:role/ambient',
        ]);

        try {
            $client = self::clientOf(S3FilesystemFactory::fromConfig(self::config([
                'FILESYSTEM_S3_ACCESS_KEY_ID' => 'scoped-id',
                'FILESYSTEM_S3_SECRET_ACCESS_KEY' => 'scoped-secret',
            ])));
        } finally {
            self::setAmbient($restore);
        }

        $provider = self::credentialProviderOf($client);
        self::assertInstanceOf(Credentials::class, $provider);
        self::assertSame('scoped-id', $provider->getAccessKeyId());
        self::assertSame('scoped-secret', $provider->getSecretKey());
        self::assertNull($provider->getSessionToken());
        self::assertSame($provider, $provider->getCredentials($client->getConfiguration()));
        self::assertSame('ambient-id', $client->getConfiguration()->get(Configuration::OPTION_ACCESS_KEY_ID));
    }

    public function test_scoped_keys_leave_the_access_key_options_at_their_defaults(): void
    {
        $restore = self::setAmbient(['AWS_ACCESS_KEY_ID' => null, 'AWS_SECRET_ACCESS_KEY' => null]);

        try {
            $configuration = self::configurationOf(S3FilesystemFactory::fromConfig(self::config([
                'FILESYSTEM_S3_ACCESS_KEY_ID' => 'scoped-id',
                'FILESYSTEM_S3_SECRET_ACCESS_KEY' => 'scoped-secret',
            ])));
        } finally {
            self::setAmbient($restore);
        }

        self::assertTrue($configuration->isDefault(Configuration::OPTION_ACCESS_KEY_ID));
        self::assertTrue($configuration->isDefault(Configuration::OPTION_SECRET_ACCESS_KEY));
    }

    public function test_a_named_connections_keys_do_not_reach_the_default_connection(): void
    {
        $config = self::config([
            'FILESYSTEM_BACKUPS_S3_BUCKET' => 'backups-bucket',
            'FILESYSTEM_BACKUPS_S3_REGION' => 'eu-west-1',
            'FILESYSTEM_BACKUPS_S3_ACCESS_KEY_ID' => 'backups-id',
            'FILESYSTEM_BACKUPS_S3_SECRET_ACCESS_KEY' => 'backups-secret',
        ]);

        $backups = self::credentialProviderOf(self::clientOf(S3FilesystemFactory::fromConfig($config, 'backups')));
        $default = self::credentialProviderOf(self::clientOf(S3FilesystemFactory::fromConfig($config)));

        self::assertInstanceOf(Credentials::class, $backups);
        self::assertSame('backups-id', $backups->getAccessKeyId());
        self::assertInstanceOf(CredentialChain::class, $default);
    }

    public function test_without_scoped_keys_the_aws_credential_chain_resolves(): void
    {
        $provider = self::credentialProviderOf(self::clientOf(S3FilesystemFactory::fromConfig(self::config())));

        self::assertInstanceOf(CredentialChain::class, $provider);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function partialCredentials(): iterable
    {
        yield 'id alone' => ['FILESYSTEM_BACKUPS_S3_ACCESS_KEY_ID', 'backups-id'];
        yield 'secret alone' => ['FILESYSTEM_BACKUPS_S3_SECRET_ACCESS_KEY', 'backups-secret'];
    }

    #[DataProvider('partialCredentials')]
    public function test_one_scoped_key_alone_is_refused_naming_both_and_no_value(string $key, string $value): void
    {
        $config = new Config([
            'FILESYSTEM_BACKUPS_S3_BUCKET' => 'backups-bucket',
            'FILESYSTEM_BACKUPS_S3_REGION' => 'eu-west-1',
            $key => $value,
        ]);

        try {
            S3FilesystemFactory::fromConfig($config, 'backups');
            self::fail('A lone credential key was accepted.');
        } catch (S3ConfigurationException $exception) {
            self::assertStringContainsString('FILESYSTEM_BACKUPS_S3_ACCESS_KEY_ID', $exception->getMessage());
            self::assertStringContainsString('FILESYSTEM_BACKUPS_S3_SECRET_ACCESS_KEY', $exception->getMessage());
            self::assertStringNotContainsString($value, $exception->getMessage());
        }
    }

    /**
     * Sets or, for null, unsets each variable in $_ENV and the process
     * environment, which AsyncAws reads in that order, and returns the
     * previous values in the same shape.
     *
     * @param array<string, ?string> $values
     *
     * @return array<string, ?string>
     */
    private static function setAmbient(array $values): array
    {
        $previous = [];

        foreach ($values as $name => $value) {
            $previous[$name] = isset($_ENV[$name]) ? (string) $_ENV[$name] : null;

            if ($value === null) {
                unset($_ENV[$name]);
                putenv($name);
            } else {
                $_ENV[$name] = $value;
                putenv("{$name}={$value}");
            }
        }

        return $previous;
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

    private static function credentialProviderOf(S3Client $client): CredentialProvider
    {
        $provider = new ReflectionProperty(AbstractApi::class, 'credentialProvider')->getValue($client);

        self::assertInstanceOf(CredentialProvider::class, $provider);

        return $provider;
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
