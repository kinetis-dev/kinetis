<?php

declare(strict_types=1);

namespace Kinetis\StorageS3;

use AsyncAws\Core\Credentials\CacheProvider;
use AsyncAws\Core\Credentials\ChainProvider;
use AsyncAws\Core\Credentials\ConfigurationProvider;
use AsyncAws\Core\Credentials\ContainerProvider;
use AsyncAws\Core\Credentials\CredentialProvider;
use AsyncAws\Core\Credentials\IniFileProvider;
use AsyncAws\Core\Credentials\InstanceProvider;
use AsyncAws\Core\Credentials\WebIdentityProvider;
use AsyncAws\S3\S3Client;
use Kinetis\Config\Config;
use Kinetis\RevoltHttpClient\AmpHttpClientFactory;
use League\Flysystem\AsyncAwsS3\AsyncAwsS3Adapter;
use League\Flysystem\Filesystem;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Builds a League\Flysystem\Filesystem backed by AsyncAwsS3Adapter, with
 * Kinetis\RevoltHttpClient\AmpHttpClientFactory::create() injected as
 * S3Client's transport, so an S3 call suspends the calling Fiber instead
 * of blocking the worker. Credentials are not read from Kinetis\Config:
 * AsyncAws resolves them from AWS_ACCESS_KEY_ID/AWS_SECRET_ACCESS_KEY,
 * the shared credentials and config files, or an IAM role on its own,
 * the standard AWS SDK convention, and a second source of truth for the
 * same thing would only compete with it.
 *
 * The chain that resolution runs through is built here rather than left
 * to AsyncAws's own default — see credentialProvider().
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

        $transport = AmpHttpClientFactory::create();
        $client = new S3Client($configuration, self::credentialProvider($transport), $transport);
        $adapter = new AsyncAwsS3Adapter($client, $bucket, $prefix);

        return new Filesystem($adapter);
    }

    /**
     * AsyncAws's own default chain, in its own provider order, with one
     * change: every provider that reaches the network is handed
     * $transport. ChainProvider::createDefaultChain() builds
     * ConfigurationProvider with no client, and that provider is the one
     * that calls STS when AWS_ROLE_ARN is set — with no client it
     * constructs a blocking Symfony transport and assumes the role on
     * the worker thread, on first resolution and again on every expiry.
     *
     * CacheProvider holds the resolved credentials until they expire, so
     * the chain runs again only at refresh.
     *
     * The shared credentials file, the shared config file and any
     * web-identity or pod-identity token file are read with native
     * blocking calls inside the providers that consult them; that is
     * AsyncAws's resolution and this package does not reimplement it.
     */
    private static function credentialProvider(HttpClientInterface $transport): CredentialProvider
    {
        return new CacheProvider(new ChainProvider([
            new ConfigurationProvider($transport),
            new WebIdentityProvider(null, null, $transport),
            new IniFileProvider(null, null, $transport),
            new ContainerProvider($transport),
            new InstanceProvider($transport),
        ]));
    }
}
