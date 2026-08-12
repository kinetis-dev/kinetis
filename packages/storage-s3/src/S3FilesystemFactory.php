<?php

declare(strict_types=1);

namespace Kinetis\StorageS3;

use AsyncAws\S3\S3Client;
use Kinetis\Config\Config;
use Kinetis\RevoltHttpClient\AmpHttpClientFactory;
use League\Flysystem\AsyncAwsS3\AsyncAwsS3Adapter;
use League\Flysystem\Filesystem;

/**
 * Builds a League\Flysystem\Filesystem backed by AsyncAwsS3Adapter, with
 * Kinetis\RevoltHttpClient\AmpHttpClientFactory::create() injected as
 * S3Client's transport instead of the default blocking one — the same
 * non-blocking guarantee Kinetis\Storage\AmpFileAdapter gives local
 * storage. Credentials are deliberately not read from Kinetis\Config at
 * all: AsyncAws\Core\Configuration already resolves them from
 * AWS_ACCESS_KEY_ID/AWS_SECRET_ACCESS_KEY or an IAM role on its own, the
 * standard AWS SDK convention — reinventing that here would just be a
 * second, competing source of truth for the same thing.
 *
 * $connection selects a named connection via Config::scopedKey() —
 * plugged into Kinetis\Storage\FilesystemFactory's own dispatch when
 * FILESYSTEM_DRIVER=s3, never resolved automatically by type.
 */
final class S3FilesystemFactory
{
    public static function fromConfig(Config $config, string $connection = 'default'): Filesystem
    {
        $bucket = $config->required(Config::scopedKey('FILESYSTEM_S3_BUCKET', $connection));
        $region = $config->required(Config::scopedKey('FILESYSTEM_S3_REGION', $connection));
        $prefix = $config->string(Config::scopedKey('FILESYSTEM_S3_PREFIX', $connection), '');
        $endpoint = $config->get(Config::scopedKey('FILESYSTEM_S3_ENDPOINT', $connection));

        $configuration = [
            'region' => $region,
            // S3-compatible non-AWS endpoints (MinIO and similar) commonly
            // need path-style addressing (http://endpoint/bucket/key)
            // rather than AWS's own virtual-hosted-style default
            // (http://bucket.endpoint/key) — AsyncAws\Core\Configuration
            // defaults this to "false", matching real AWS S3 itself, and
            // (like every option here) reads it as a string, not a bool.
            'pathStyleEndpoint' => $config->bool(Config::scopedKey('FILESYSTEM_S3_PATH_STYLE', $connection), false) ? 'true' : 'false',
        ];

        if ($endpoint !== null) {
            $configuration['endpoint'] = $endpoint;
        }

        $client = new S3Client($configuration, null, AmpHttpClientFactory::create());
        $adapter = new AsyncAwsS3Adapter($client, $bucket, $prefix);

        return new Filesystem($adapter);
    }
}
