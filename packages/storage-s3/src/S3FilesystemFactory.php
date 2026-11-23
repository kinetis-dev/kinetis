<?php

declare(strict_types=1);

namespace Kinetis\StorageS3;

use Kinetis\Config\Config;
use League\Flysystem\AsyncAwsS3\PortableVisibilityConverter;
use League\Flysystem\Config as FlysystemConfig;
use League\Flysystem\Filesystem;
use League\Flysystem\Visibility;

/**
 * Builds a League\Flysystem\Filesystem backed by this package's S3Adapter
 * over the S3Client of one S3Connection, which owns how the
 * `FILESYSTEM_S3_*` keys become a client, a transport and credentials.
 *
 * Storage is private. The adapter is given a visibility converter whose
 * directory default is private too, so createDirectory() asks for no
 * broader access than a write does, and `retain_visibility` is off so
 * copy() and move() never read the source object's ACL. Only
 * `ContentType` is forwarded from a Flysystem config array; every other
 * per-object S3 option, `ACL` included, is not this package's to pass
 * through. Public objects are a bucket policy's to grant.
 *
 * $connection selects a named connection via Config::scopedKey() —
 * plugged into Kinetis\Storage\FilesystemFactory's own dispatch when
 * FILESYSTEM_DRIVER=s3, never resolved automatically by type.
 */
final class S3FilesystemFactory
{
    public static function fromConfig(Config $config, string $connection = 'default'): Filesystem
    {
        $s3 = S3Connection::fromConfig($config, $connection);
        $adapter = new S3Adapter(
            $s3->client,
            $s3->bucket,
            $s3->prefix,
            new PortableVisibilityConverter(Visibility::PRIVATE),
            forwardedOptions: ['ContentType'],
        );

        return new Filesystem($adapter, [FlysystemConfig::OPTION_RETAIN_VISIBILITY => false]);
    }
}
