<?php

declare(strict_types=1);

namespace Kinetis\StorageS3\Tests;

use AsyncAws\Core\Configuration;
use AsyncAws\Core\Credentials\CredentialProvider;
use AsyncAws\Core\Credentials\Credentials;
use Closure;
use DateTimeImmutable;
use InvalidArgumentException;
use Kinetis\Config\Config;
use Kinetis\StorageS3\DirectUploads;
use Kinetis\StorageS3\Exception\S3OperationException;
use Kinetis\StorageS3\S3Client;
use Kinetis\StorageS3\S3Connection;
use League\Flysystem\CorruptedPathDetected;
use League\Flysystem\PathTraversalDetected;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class DirectUploadsTest extends TestCase
{
    private const ACCESS_KEY_ID = 'AKIAIOSFODNN7EXAMPLE';

    private const SECRET = 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY';

    /**
     * The policy and signature AWS publishes in "Example: Browser-Based
     * Upload using HTTP POST (Using AWS Signature Version 4)". That
     * policy uses starts-with and ACL conditions authorize() never
     * emits, so the vector exercises the signing function itself.
     */
    private const AWS_EXAMPLE_POLICY = 'eyAiZXhwaXJhdGlvbiI6ICIyMDE1LTEyLTMwVDEyOjAwOjAwLjAwMFoiLA0KICAiY29uZGl0aW9ucyI6IFsNCiAgICB7ImJ1Y2tldCI6ICJzaWd2NGV4YW1wbGVidWNrZXQifSwNCiAgICBbInN0YXJ0cy13aXRoIiwgIiRrZXkiLCAidXNlci91c2VyMS8iXSwNCiAgICB7ImFjbCI6ICJwdWJsaWMtcmVhZCJ9LA0KICAgIHsic3VjY2Vzc19hY3Rpb25fcmVkaXJlY3QiOiAiaHR0cDovL3NpZ3Y0ZXhhbXBsZWJ1Y2tldC5zMy5hbWF6b25hd3MuY29tL3N1Y2Nlc3NmdWxfdXBsb2FkLmh0bWwifSwNCiAgICBbInN0YXJ0cy13aXRoIiwgIiRDb250ZW50LVR5cGUiLCAiaW1hZ2UvIl0sDQogICAgeyJ4LWFtei1tZXRhLXV1aWQiOiAiMTQzNjUxMjM2NTEyNzQifSwNCiAgICB7IngtYW16LXNlcnZlci1zaWRlLWVuY3J5cHRpb24iOiAiQUVTMjU2In0sDQogICAgWyJzdGFydHMtd2l0aCIsICIkeC1hbXotbWV0YS10YWciLCAiIl0sDQoNCiAgICB7IngtYW16LWNyZWRlbnRpYWwiOiAiQUtJQUlPU0ZPRE5ON0VYQU1QTEUvMjAxNTEyMjkvdXMtZWFzdC0xL3MzL2F3czRfcmVxdWVzdCJ9LA0KICAgIHsieC1hbXotYWxnb3JpdGhtIjogIkFXUzQtSE1BQy1TSEEyNTYifSwNCiAgICB7IngtYW16LWRhdGUiOiAiMjAxNTEyMjlUMDAwMDAwWiIgfQ0KICBdDQp9';

    private const AWS_EXAMPLE_SIGNATURE = '8afdbf4008c03f22c2cd3cdb72e4afbb1f6a588f3255ac628749a66d7f09699e';

    public function test_the_signing_function_reproduces_the_aws_worked_example(): void
    {
        self::assertSame(
            self::AWS_EXAMPLE_SIGNATURE,
            self::sign(self::SECRET, '20151229', 'us-east-1', self::AWS_EXAMPLE_POLICY),
        );
    }

    public function test_one_changed_input_byte_changes_the_signature(): void
    {
        $policy = self::AWS_EXAMPLE_POLICY;
        $policy[10] = $policy[10] === 'A' ? 'B' : 'A';

        self::assertNotSame(self::AWS_EXAMPLE_SIGNATURE, self::sign(self::SECRET, '20151229', 'us-east-1', $policy));
        self::assertNotSame(self::AWS_EXAMPLE_SIGNATURE, self::sign(substr(self::SECRET, 0, -1) . 'X', '20151229', 'us-east-1', self::AWS_EXAMPLE_POLICY));
        self::assertNotSame(self::AWS_EXAMPLE_SIGNATURE, self::sign(self::SECRET, '20151228', 'us-east-1', self::AWS_EXAMPLE_POLICY));
        self::assertNotSame(self::AWS_EXAMPLE_SIGNATURE, self::sign(self::SECRET, '20151229', 'us-east-2', self::AWS_EXAMPLE_POLICY));
    }

    /**
     * The policy and signature below were computed independently of this
     * package, from the SigV4 POST rules.
     */
    public function test_static_keys_sign_exactly_the_documented_policy_and_fields(): void
    {
        $post = self::uploads()->authorize('user/user1/photo.jpg', 10_485_760);

        $policy = 'eyJleHBpcmF0aW9uIjoiMjAxNS0xMi0yOVQwMDoxNTowMC4wMDBaIiwiY29uZGl0aW9ucyI6W3siYnVja2V0Ijoic2lndjRleGFtcGxlYnVja2V0In0seyJrZXkiOiJ1c2VyL3VzZXIxL3Bob3RvLmpwZyJ9LFsiY29udGVudC1sZW5ndGgtcmFuZ2UiLDEsMTA0ODU3NjBdLHsieC1hbXotYWxnb3JpdGhtIjoiQVdTNC1ITUFDLVNIQTI1NiJ9LHsieC1hbXotY3JlZGVudGlhbCI6IkFLSUFJT1NGT0ROTjdFWEFNUExFLzIwMTUxMjI5L3VzLWVhc3QtMS9zMy9hd3M0X3JlcXVlc3QifSx7IngtYW16LWRhdGUiOiIyMDE1MTIyOVQwMDAwMDBaIn1dfQ==';

        self::assertSame('https://sigv4examplebucket.s3.us-east-1.amazonaws.com/', $post->url);
        self::assertSame([
            'key' => 'user/user1/photo.jpg',
            'x-amz-algorithm' => 'AWS4-HMAC-SHA256',
            'x-amz-credential' => 'AKIAIOSFODNN7EXAMPLE/20151229/us-east-1/s3/aws4_request',
            'x-amz-date' => '20151229T000000Z',
            'policy' => $policy,
            'x-amz-signature' => '3368de60d8859a5a2c372636ddd3ae1a3fc09e785bf915d56c4ea76ecbfd7399',
        ], $post->fields);
        self::assertEquals(new DateTimeImmutable('2015-12-29T00:15:00Z'), $post->expiresAt);
        self::assertStringNotContainsString(self::SECRET, (string) json_encode($post));
    }

    public function test_a_session_token_and_a_content_type_are_both_sent_and_pinned(): void
    {
        $uploads = self::withCredentials(new Credentials(self::ACCESS_KEY_ID, self::SECRET, 'the-session-token'));

        $post = $uploads->authorize('avatars/a.png', 2_000_000, 600, 'image/png');

        self::assertSame(
            ['key', 'x-amz-algorithm', 'x-amz-credential', 'x-amz-date', 'x-amz-security-token', 'Content-Type', 'policy', 'x-amz-signature'],
            array_keys($post->fields),
        );
        self::assertSame('the-session-token', $post->fields['x-amz-security-token']);
        self::assertSame('image/png', $post->fields['Content-Type']);
        self::assertSame([
            'expiration' => '2015-12-29T00:10:00.000Z',
            'conditions' => [
                ['bucket' => 'sigv4examplebucket'],
                ['key' => 'avatars/a.png'],
                ['content-length-range', 1, 2_000_000],
                ['x-amz-algorithm' => 'AWS4-HMAC-SHA256'],
                ['x-amz-credential' => 'AKIAIOSFODNN7EXAMPLE/20151229/us-east-1/s3/aws4_request'],
                ['x-amz-date' => '20151229T000000Z'],
                ['x-amz-security-token' => 'the-session-token'],
                ['Content-Type' => 'image/png'],
            ],
        ], self::policyOf($post->fields));
        self::assertSame(
            self::sign(self::SECRET, '20151229', 'us-east-1', $post->fields['policy']),
            $post->fields['x-amz-signature'],
        );
    }

    public function test_the_expiry_is_capped_at_the_credentials_own(): void
    {
        $uploads = self::withCredentials(
            new Credentials(self::ACCESS_KEY_ID, self::SECRET, 'token', new DateTimeImmutable('2015-12-29T00:05:30.750Z')),
        );

        $post = $uploads->authorize('a.txt', 1, 3600);

        self::assertEquals(new DateTimeImmutable('2015-12-29T00:05:30Z'), $post->expiresAt);
        self::assertSame('2015-12-29T00:05:30.000Z', self::policyOf($post->fields)['expiration']);
    }

    public function test_credentials_outliving_the_window_leave_it_alone(): void
    {
        $uploads = self::withCredentials(
            new Credentials(self::ACCESS_KEY_ID, self::SECRET, 'token', new DateTimeImmutable('2015-12-29T06:00:00Z')),
        );

        self::assertEquals(new DateTimeImmutable('2015-12-29T00:01:00Z'), $uploads->authorize('a.txt', 1, 60)->expiresAt);
    }

    public function test_credentials_resolve_through_the_connections_provider_on_every_call(): void
    {
        $provider = new class implements CredentialProvider {
            public int $calls = 0;

            public function getCredentials(Configuration $configuration): Credentials
            {
                ++$this->calls;

                return new Credentials('AKID' . $this->calls, 'secret');
            }
        };
        $uploads = self::withProvider($provider);

        self::assertStringStartsWith('AKID1/', $uploads->authorize('a.txt', 1)->fields['x-amz-credential']);
        self::assertStringStartsWith('AKID2/', $uploads->authorize('a.txt', 1)->fields['x-amz-credential']);
    }

    public function test_no_credentials_is_refused(): void
    {
        $provider = new class implements CredentialProvider {
            public function getCredentials(Configuration $configuration): ?Credentials
            {
                return null;
            }
        };

        $this->expectException(S3OperationException::class);
        self::withProvider($provider)->authorize('a.txt', 1);
    }

    public function test_the_key_is_normalized_and_prefixed_as_the_filesystem_addresses_it(): void
    {
        $uploads = self::uploads(['FILESYSTEM_S3_PREFIX' => 'tenant-7']);

        $post = $uploads->authorize('./incoming//a/../b.bin', 1);

        self::assertSame('tenant-7/incoming/b.bin', $post->fields['key']);
        self::assertSame(['key' => 'tenant-7/incoming/b.bin'], self::policyOf($post->fields)['conditions'][1]);
    }

    /**
     * @return iterable<string, array{string, int, int, ?string}>
     */
    public static function refusedArguments(): iterable
    {
        yield 'the root' => ['/./', 1, 900, null];
        yield 'a browser-substituted name' => ['uploads/${filename}', 1, 900, null];
        yield 'a zero size' => ['a.txt', 0, 900, null];
        yield 'a negative size' => ['a.txt', -1, 900, null];
        yield 'a zero expiry' => ['a.txt', 1, 0, null];
        yield 'an expiry beyond an hour' => ['a.txt', 1, 3601, null];
        yield 'an empty content type' => ['a.txt', 1, 900, ''];
        yield 'a content type carrying a newline' => ['a.txt', 1, 900, "text/plain\r\nX-Injected: 1"];
        yield 'a content type carrying a C1 control' => ['a.txt', 1, 900, "text/plain\u{85}"];
        yield 'a content type that is not UTF-8' => ['a.txt', 1, 900, "text/\xff"];
    }

    #[DataProvider('refusedArguments')]
    public function test_an_unusable_argument_is_refused(string $path, int $maxBytes, int $expires, ?string $contentType): void
    {
        $this->expectException(InvalidArgumentException::class);
        self::uploads()->authorize($path, $maxBytes, $expires, $contentType);
    }

    public function test_the_boundaries_of_each_range_are_accepted(): void
    {
        $uploads = self::uploads();

        self::assertSame(['content-length-range', 1, 1], self::policyOf($uploads->authorize('a.txt', 1, 1)->fields)['conditions'][2]);
        self::assertEquals(new DateTimeImmutable('2015-12-29T01:00:00Z'), $uploads->authorize('a.txt', 1, 3600)->expiresAt);
    }

    public function test_a_path_escaping_the_root_is_refused(): void
    {
        $this->expectException(PathTraversalDetected::class);
        self::uploads()->authorize('../outside.txt', 1);
    }

    public function test_a_path_carrying_a_control_character_is_refused(): void
    {
        $this->expectException(CorruptedPathDetected::class);
        self::uploads()->authorize("a\nb.txt", 1);
    }

    /**
     * @return iterable<string, array{array<string, string>, string, string}>
     */
    public static function destinations(): iterable
    {
        yield 'a DNS-compatible bucket, virtual-hosted' => [
            ['FILESYSTEM_S3_BUCKET' => 'my-app-bucket', 'FILESYSTEM_S3_REGION' => 'eu-west-1'],
            'https://my-app-bucket.s3.eu-west-1.amazonaws.com/',
            '/eu-west-1/',
        ];
        yield 'a dotted bucket, path-style' => [
            ['FILESYSTEM_S3_BUCKET' => 'my.app.bucket', 'FILESYSTEM_S3_REGION' => 'eu-west-1'],
            'https://s3.eu-west-1.amazonaws.com/my.app.bucket/',
            '/eu-west-1/',
        ];
        yield 'a custom endpoint, path-style' => [
            ['FILESYSTEM_S3_BUCKET' => 'my-app-bucket', 'FILESYSTEM_S3_REGION' => 'auto', 'FILESYSTEM_S3_ENDPOINT' => 'https://minio.example:9000/'],
            'https://minio.example:9000/my-app-bucket/',
            '/auto/',
        ];
        yield 'a FIPS pseudo-region signs as its real region' => [
            ['FILESYSTEM_S3_BUCKET' => 'my-app-bucket', 'FILESYSTEM_S3_REGION' => 'fips-us-east-1'],
            'https://my-app-bucket.s3-fips.us-east-1.amazonaws.com/',
            '/us-east-1/',
        ];
    }

    /**
     * @param array<string, string> $settings
     */
    #[DataProvider('destinations')]
    public function test_the_form_targets_the_clients_own_bucket_url_and_signing_region(array $settings, string $url, string $scope): void
    {
        $post = self::uploads($settings)->authorize('a/b c.txt', 1);

        self::assertSame($url, $post->url);
        self::assertSame('a/b c.txt', $post->fields['key']);
        self::assertStringContainsString($scope, $post->fields['x-amz-credential']);
    }

    /**
     * @param array<string, string> $extra
     */
    private static function uploads(array $extra = []): DirectUploads
    {
        $connection = S3Connection::fromConfig(new Config($extra + [
            'FILESYSTEM_S3_BUCKET' => 'sigv4examplebucket',
            'FILESYSTEM_S3_REGION' => 'us-east-1',
            'FILESYSTEM_S3_ACCESS_KEY_ID' => self::ACCESS_KEY_ID,
            'FILESYSTEM_S3_SECRET_ACCESS_KEY' => self::SECRET,
        ]), 'default');

        return self::withClock($connection);
    }

    /**
     * A Credentials instance is its own provider but answers null once
     * expired, and these expire in 2015; this one hands them back as-is.
     */
    private static function withCredentials(Credentials $credentials): DirectUploads
    {
        return self::withProvider(new readonly class ($credentials) implements CredentialProvider {
            public function __construct(private Credentials $credentials)
            {
            }

            public function getCredentials(Configuration $configuration): Credentials
            {
                return $this->credentials;
            }
        });
    }

    /**
     * Temporary credentials only come from STS, so the connection is
     * built here around a provider rather than from configuration.
     */
    private static function withProvider(CredentialProvider $provider): DirectUploads
    {
        $client = new S3Client(Configuration::create(['region' => 'us-east-1']), $provider);
        $connection = Closure::bind(
            static fn (): S3Connection => new S3Connection('sigv4examplebucket', '', $client, $provider),
            null,
            S3Connection::class,
        )();

        return self::withClock($connection);
    }

    private static function withClock(S3Connection $connection): DirectUploads
    {
        $now = self::now();

        return Closure::bind(
            static fn (): DirectUploads => new DirectUploads($connection, $now),
            null,
            DirectUploads::class,
        )();
    }

    private static function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2015-12-29T00:00:00.400Z');
    }

    private static function sign(string $secret, string $date, string $region, string $policy): string
    {
        $result = new ReflectionMethod(DirectUploads::class, 'signature')->invoke(null, $secret, $date, $region, $policy);
        self::assertIsString($result);

        return $result;
    }

    /**
     * @param array<string, string> $fields
     *
     * @return array{expiration: string, conditions: list<mixed>}
     */
    private static function policyOf(array $fields): array
    {
        /** @var array{expiration: string, conditions: list<mixed>} */
        return json_decode(base64_decode($fields['policy'], true) ?: '', true, flags: JSON_THROW_ON_ERROR);
    }
}
