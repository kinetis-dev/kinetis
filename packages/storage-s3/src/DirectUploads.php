<?php

declare(strict_types=1);

namespace Kinetis\StorageS3;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Kinetis\Config\Config;
use Kinetis\StorageS3\Exception\S3OperationException;
use League\Flysystem\PathPrefixer;
use League\Flysystem\WhitespacePathNormalizer;
use SensitiveParameter;

/**
 * Signs S3 POST policies that let a browser upload one object straight to
 * a connection's bucket, so the file never passes through the worker.
 *
 * The key is the path normalized and prefixed exactly as the connection's
 * Filesystem addresses it, so fileExists() and fileSize() on the same
 * path confirm the upload afterwards. The policy pins the bucket, the
 * exact key, a 1..$maxBytes size range and, when given, the exact
 * Content-Type; docs/appendix-storage.md states its conditions in full.
 *
 * Application-scoped: it holds the connection and nothing from any
 * request. Credentials are resolved on each
 * call through the connection's own provider, which may suspend the
 * calling Fiber; the secret key only derives the signing key and never
 * enters the result.
 */
final readonly class DirectUploads
{
    private const MAX_EXPIRY_SECONDS = 3600;

    private const ALGORITHM = 'AWS4-HMAC-SHA256';

    private const SERVICE = 's3';

    private WhitespacePathNormalizer $normalizer;

    private PathPrefixer $prefixer;

    private function __construct(
        private S3Connection $connection,
        private ?DateTimeImmutable $now,
    ) {
        $this->normalizer = new WhitespacePathNormalizer();
        $this->prefixer = new PathPrefixer($connection->prefix);
    }

    public static function fromConfig(Config $config, string $connection = 'default'): self
    {
        return new self(S3Connection::fromConfig($config, $connection), null);
    }

    /**
     * The path follows the Filesystem's rules: a path that climbs above
     * the root throws League\Flysystem\PathTraversalDetected and a control
     * character throws CorruptedPathDetected.
     *
     * @throws InvalidArgumentException when the path names the root or
     *         holds `${filename}`, when $maxBytes is below 1, when
     *         $expiresInSeconds is outside 1..3600, or when $contentType
     *         is empty or holds a control character
     * @throws S3OperationException when the connection resolves no
     *         credentials
     */
    public function authorize(string $path, int $maxBytes, int $expiresInSeconds = 900, ?string $contentType = null): PresignedPost
    {
        $normalized = $this->normalizer->normalizePath($path);

        if ($normalized === '') {
            throw new InvalidArgumentException('An upload path must name an object, not the storage root.');
        }

        // S3 replaces `${filename}` in a POST key with the name the
        // browser sends, which would hand part of the key to the client.
        if (str_contains($normalized, '${filename}')) {
            throw new InvalidArgumentException('An upload path must not contain ${filename}.');
        }

        if ($maxBytes < 1) {
            throw new InvalidArgumentException('The maximum upload size must be at least 1 byte.');
        }

        if ($expiresInSeconds < 1 || $expiresInSeconds > self::MAX_EXPIRY_SECONDS) {
            throw new InvalidArgumentException('An upload authorization must expire within 1 to 3600 seconds.');
        }

        // preg_match() answers false for invalid UTF-8, which is refused too.
        if ($contentType !== null && ($contentType === '' || preg_match('/\p{Cc}/u', $contentType) !== 0)) {
            throw new InvalidArgumentException('An upload Content-Type must be non-empty, valid UTF-8 without control characters.');
        }

        $client = $this->connection->client;
        $credentials = $this->connection->credentials->getCredentials($client->getConfiguration())
            ?? throw S3OperationException::noCredentials();

        $now = ($this->now ?? new DateTimeImmutable())->setTimezone(new DateTimeZone('UTC'));
        $expires = $now->getTimestamp() + $expiresInSeconds;
        $credentialsExpire = $credentials->getExpireDate()?->getTimestamp();

        if ($credentialsExpire !== null && $credentialsExpire < $expires) {
            $expires = $credentialsExpire;
        }

        $expiresAt = new DateTimeImmutable('@' . $expires);
        $date = $now->format('Ymd');
        $region = $client->signingRegion();
        $token = $credentials->getSessionToken();

        $signed = [
            'key' => $this->prefixer->prefixPath($normalized),
            'x-amz-algorithm' => self::ALGORITHM,
            'x-amz-credential' => $credentials->getAccessKeyId() . "/{$date}/{$region}/" . self::SERVICE . '/aws4_request',
            'x-amz-date' => $now->format('Ymd\THis\Z'),
        ];

        if ($token !== null) {
            $signed['x-amz-security-token'] = $token;
        }

        if ($contentType !== null) {
            $signed['Content-Type'] = $contentType;
        }

        $conditions = [
            ['bucket' => $this->connection->bucket],
            ['key' => $signed['key']],
            ['content-length-range', 1, $maxBytes],
        ];

        foreach ($signed as $field => $value) {
            if ($field !== 'key') {
                $conditions[] = [$field => $value];
            }
        }

        $policy = base64_encode(json_encode(
            ['expiration' => $expiresAt->format('Y-m-d\TH:i:s.000\Z'), 'conditions' => $conditions],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ));

        return new PresignedPost(
            $client->bucketUrl($this->connection->bucket),
            $signed + [
                'policy' => $policy,
                'x-amz-signature' => self::signature($credentials->getSecretKey(), $date, $region, $policy),
            ],
            $expiresAt,
        );
    }

    /**
     * SigV4 for a POST policy: the base64 policy itself is the string to
     * sign, under the signing key derived from the secret, the date, the
     * region and the service.
     */
    private static function signature(#[SensitiveParameter] string $secret, string $date, string $region, string $policy): string
    {
        $key = hash_hmac('sha256', $date, 'AWS4' . $secret, true);
        $key = hash_hmac('sha256', $region, $key, true);
        $key = hash_hmac('sha256', self::SERVICE, $key, true);
        $key = hash_hmac('sha256', 'aws4_request', $key, true);

        return hash_hmac('sha256', $policy, $key);
    }
}
