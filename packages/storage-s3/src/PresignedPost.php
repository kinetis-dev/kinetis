<?php

declare(strict_types=1);

namespace Kinetis\StorageS3;

use DateTimeImmutable;

/**
 * One browser upload DirectUploads::authorize() signed: a multipart POST
 * to $url carrying every entry of $fields unchanged, then the file as the
 * last part, accepted by S3 until $expiresAt.
 *
 * $fields holds the policy, its signature and, under temporary
 * credentials, the session token the browser must send; it never holds a
 * secret key.
 */
final readonly class PresignedPost
{
    /**
     * @param array<string, string> $fields
     */
    public function __construct(
        public string $url,
        public array $fields,
        public DateTimeImmutable $expiresAt,
    ) {
    }
}
