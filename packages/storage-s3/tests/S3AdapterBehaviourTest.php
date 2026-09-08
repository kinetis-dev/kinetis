<?php

declare(strict_types=1);

namespace Kinetis\StorageS3\Tests;

use AsyncAws\Core\AbstractApi;
use AsyncAws\Core\Credentials\NullProvider;
use Kinetis\Config\Config;
use Kinetis\StorageS3\S3FilesystemFactory;
use League\Flysystem\AsyncAwsS3\AsyncAwsS3Adapter;
use League\Flysystem\Filesystem;
use League\Flysystem\UnableToCopyFile;
use League\Flysystem\UnableToCreateDirectory;
use League\Flysystem\UnableToDeleteDirectory;
use League\Flysystem\UnableToMoveFile;
use League\Flysystem\UnableToWriteFile;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * What reaches the wire, proved through the vendor adapter the factory
 * really builds rather than a stand-in for it: the ACL header, the
 * request an operation does not issue, and the HTTP 200 responses S3
 * uses to report failure. Only the transport and the credential provider
 * are substituted, so the visibility converter, forwarded options and
 * `retain_visibility` under test are the ones production gets.
 */
final class S3AdapterBehaviourTest extends TestCase
{
    private const OK = '';
    private const COPIED = '<CopyObjectResult><ETag>"5d41402abc4b2a76b9719d911017c592"</ETag></CopyObjectResult>';
    private const COPY_FAILED = '<Error><Code>InternalError</Code><Message>We encountered an internal error.</Message></Error>';
    private const ONE_KEY = '<ListBucketResult><KeyCount>1</KeyCount><Contents><Key>logs/app.log</Key><Size>12</Size></Contents></ListBucketResult>';
    private const DELETE_REFUSED = '<DeleteResult><Error><Key>logs/app.log</Key><Code>AccessDenied</Code><Message>Access Denied</Message></Error></DeleteResult>';

    /**
     * @var list<array{method: string, headers: array<string, string>}>
     */
    private array $sent = [];

    public function test_a_write_carries_no_acl(): void
    {
        $this->filesystem(self::OK)->write('avatars/user-42.png', 'contents');

        self::assertSame(['PUT'], $this->methods());
        self::assertArrayNotHasKey('x-amz-acl', $this->sent[0]['headers']);
    }

    public function test_a_copy_carries_no_acl_and_reads_no_source_acl(): void
    {
        $this->filesystem(self::COPIED)->copy('a.txt', 'b.txt');

        self::assertSame(['PUT'], $this->methods());
        self::assertArrayNotHasKey('x-amz-acl', $this->sent[0]['headers']);
    }

    public function test_creating_a_directory_carries_no_acl(): void
    {
        $this->filesystem(self::OK)->createDirectory('logs');

        self::assertSame(['PUT'], $this->methods());
        self::assertArrayNotHasKey('x-amz-acl', $this->sent[0]['headers']);
    }

    public function test_explicit_public_visibility_fails_before_anything_is_sent(): void
    {
        $filesystem = $this->filesystem(self::OK);

        try {
            $filesystem->write('avatars/user-42.png', 'contents', ['visibility' => 'public']);
            self::fail('A public write must not be accepted.');
        } catch (UnableToWriteFile $exception) {
            self::assertStringContainsString('ACL other than private', $exception->getMessage());
        }

        self::assertSame([], $this->methods());
    }

    public function test_a_public_directory_fails_before_anything_is_sent(): void
    {
        $filesystem = $this->filesystem(self::OK);

        try {
            $filesystem->createDirectory('logs', ['directory_visibility' => 'public']);
            self::fail('A public directory must not be accepted.');
        } catch (UnableToCreateDirectory) {
        }

        self::assertSame([], $this->methods());
    }

    public function test_a_copy_answered_with_an_error_document_under_http_200_fails(): void
    {
        $this->expectException(UnableToCopyFile::class);

        $this->filesystem(self::COPY_FAILED)->copy('a.txt', 'b.txt');
    }

    public function test_a_move_whose_copy_failed_deletes_nothing(): void
    {
        $filesystem = $this->filesystem(self::COPY_FAILED);

        try {
            $filesystem->move('a.txt', 'b.txt');
            self::fail('A move must not report success when its copy failed.');
        } catch (UnableToMoveFile) {
        }

        self::assertSame(['PUT'], $this->methods());
    }

    public function test_a_batch_delete_reporting_per_key_errors_fails_the_directory_delete(): void
    {
        $filesystem = $this->filesystem(self::ONE_KEY, self::DELETE_REFUSED);

        try {
            $filesystem->deleteDirectory('logs');
            self::fail('A directory delete must not report success when a key survived.');
        } catch (UnableToDeleteDirectory $exception) {
            self::assertStringContainsString('per-key errors', $exception->getMessage());
        }

        self::assertSame(['GET', 'POST'], $this->methods());
    }

    /**
     * The bodies are answered in order, each with HTTP 200 — the status
     * S3 returns for the failures under test.
     */
    private function filesystem(string ...$bodies): Filesystem
    {
        $transport = new MockHttpClient(function (string $method, string $url, array $options) use (&$bodies): MockResponse {
            /** @var list<string> $headers */
            $headers = $options['headers'];
            $this->sent[] = ['method' => $method, 'headers' => self::headers($headers)];

            return new MockResponse((string) array_shift($bodies), ['http_code' => 200]);
        });

        $filesystem = S3FilesystemFactory::fromConfig(new Config([
            'FILESYSTEM_S3_BUCKET' => 'default-bucket',
            'FILESYSTEM_S3_REGION' => 'us-east-1',
            'FILESYSTEM_S3_ENDPOINT' => 'https://s3.example-compatible.test',
        ]));

        $adapter = new ReflectionProperty(Filesystem::class, 'adapter')->getValue($filesystem);

        self::assertInstanceOf(AsyncAwsS3Adapter::class, $adapter);

        $client = new ReflectionProperty(AsyncAwsS3Adapter::class, 'client')->getValue($adapter);

        new ReflectionProperty(AbstractApi::class, 'httpClient')->setValue($client, $transport);
        new ReflectionProperty(AbstractApi::class, 'credentialProvider')->setValue($client, new NullProvider());

        return $filesystem;
    }

    /**
     * Symfony hands a request's headers to a transport as raw
     * "Name: value" lines.
     *
     * @param list<string> $lines
     *
     * @return array<string, string>
     */
    private static function headers(array $lines): array
    {
        $headers = [];

        foreach ($lines as $line) {
            [$name, $value] = explode(':', $line, 2);
            $headers[strtolower($name)] = ltrim($value);
        }

        return $headers;
    }

    /**
     * @return list<string>
     */
    private function methods(): array
    {
        return array_map(static fn (array $request): string => $request['method'], $this->sent);
    }
}
