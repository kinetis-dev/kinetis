<?php

declare(strict_types=1);

namespace Kinetis\StorageS3\Exception;

use RuntimeException;

/**
 * A request Kinetis\StorageS3\S3Client will not send, or a response it
 * will not report as success. AsyncAwsS3Adapter catches every Throwable
 * these methods raise and rethrows it as the matching Flysystem
 * UnableTo* exception, so callers see the ordinary Flysystem contract.
 *
 * A message names the S3 operation and nothing else — no object key, no
 * ACL, and nothing S3 returned. The Flysystem exception wrapping it
 * already carries the path, and the rest is bucket content that has no
 * business in a log line.
 */
final class S3OperationException extends RuntimeException
{
    public static function unsupportedAcl(string $operation): self
    {
        return new self(
            "{$operation} was asked for an ACL other than private. kinetis/storage-s3 sends no ACL at all, so a bucket with ACLs disabled accepts its requests; grant object access through a bucket policy instead.",
        );
    }

    public static function copyWithoutEtag(): self
    {
        return new self(
            'CopyObject returned no ETag. S3 reports a mid-copy failure as an error document under HTTP 200, so the destination object does not exist.',
        );
    }

    public static function batchDeleteFailed(): self
    {
        return new self(
            'DeleteObjects reported per-key errors, so objects under the prefix are still there.',
        );
    }
}
