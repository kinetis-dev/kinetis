<?php

declare(strict_types=1);

namespace Kinetis\StorageS3;

use AsyncAws\Core\Configuration;
use AsyncAws\Core\Credentials\ConfigurationProvider;
use AsyncAws\Core\Credentials\ContainerProvider;
use AsyncAws\Core\Credentials\CredentialProvider;
use AsyncAws\Core\Credentials\IniFileProvider;
use AsyncAws\Core\Credentials\InstanceProvider;
use AsyncAws\Core\Credentials\WebIdentityProvider;
use Kinetis\Config\Config;
use Kinetis\RevoltHttpClient\AmpHttpClientFactory;
use Kinetis\StorageS3\Exception\S3ConfigurationException;
use League\Flysystem\AsyncAwsS3\AsyncAwsS3Adapter;
use League\Flysystem\AsyncAwsS3\PortableVisibilityConverter;
use League\Flysystem\Config as FlysystemConfig;
use League\Flysystem\Filesystem;
use League\Flysystem\Visibility;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Builds a League\Flysystem\Filesystem backed by AsyncAwsS3Adapter over
 * this package's own S3Client, with
 * Kinetis\RevoltHttpClient\AmpHttpClientFactory::create() injected as the
 * client's transport, so an S3 call suspends the calling Fiber instead of
 * blocking the worker. Credentials are not read from Kinetis\Config:
 * AsyncAws resolves them from AWS_ACCESS_KEY_ID/AWS_SECRET_ACCESS_KEY,
 * the shared credentials and config files, or an IAM role on its own,
 * the standard AWS SDK convention, and a second source of truth for the
 * same thing would only compete with it.
 *
 * The chain that resolution runs through is built here rather than left
 * to AsyncAws's own default — see credentialProvider().
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
    /**
     * Seconds. Applied to each S3 request on its own — connect, idle and
     * total transfer — not as one deadline across a Flysystem operation
     * that issues several, such as a paginated deleteDirectory().
     */
    private const DEFAULT_TIMEOUT = 60.0;

    public static function fromConfig(Config $config, string $connection = 'default'): Filesystem
    {
        $bucket = $config->required(Config::scopedKey('FILESYSTEM_S3_BUCKET', $connection));
        $region = $config->required(Config::scopedKey('FILESYSTEM_S3_REGION', $connection));
        $prefix = $config->string(Config::scopedKey('FILESYSTEM_S3_PREFIX', $connection), '');

        $transport = AmpHttpClientFactory::create(self::transportOptions($config, $connection));
        $configuration = self::configuration($config, $connection, $region);

        $client = new S3Client($configuration, self::credentialProvider($transport), $transport);
        $adapter = new AsyncAwsS3Adapter(
            $client,
            $bucket,
            $prefix,
            new PortableVisibilityConverter(Visibility::PRIVATE),
            forwardedOptions: ['ContentType'],
        );

        return new Filesystem($adapter, [FlysystemConfig::OPTION_RETAIN_VISIBILITY => false]);
    }

    /**
     * Without FILESYSTEM_S3_ENDPOINT the destination is AsyncAws's own
     * regional endpoint table. AsyncAws would otherwise fall back to an
     * ambient AWS_ENDPOINT_URL, which is how a machine-wide setting for
     * some other tool silently sends this application's objects
     * somewhere else; Configuration::isDefault() is what distinguishes
     * that fallback from the table.
     *
     * An explicit endpoint uses path-style addressing, because the
     * virtual-hosted style AWS itself prefers needs the bucket to be a
     * DNS label under the endpoint host, which an S3-compatible service
     * on a fixed hostname does not provide.
     */
    private static function configuration(Config $config, string $connection, string $region): Configuration
    {
        $endpointKey = Config::scopedKey('FILESYSTEM_S3_ENDPOINT', $connection);
        $endpoint = $config->string($endpointKey, '');

        if ($endpoint === '') {
            $configuration = Configuration::create(['region' => $region]);

            if (!$configuration->isDefault(Configuration::OPTION_ENDPOINT)) {
                throw S3ConfigurationException::ambientEndpoint($endpointKey);
            }

            return $configuration;
        }

        return Configuration::create([
            'region' => $region,
            'endpoint' => self::origin($config, $connection, $endpointKey, $endpoint),
            'pathStyleEndpoint' => 'true',
        ]);
    }

    /**
     * An endpoint is an origin and nothing else: AsyncAws appends the
     * bucket and key to it, so a path, query or fragment would land in
     * the middle of a signed request, and userinfo is a credential the
     * SigV4 signature does not cover. Rebuilding the accepted parts
     * rather than passing the string through keeps whatever else
     * parse_url() tolerated out of the URL.
     *
     * Plain HTTP is an explicit opt-in rather than an address check:
     * `http://minio:9000` between containers on one Compose network is
     * as legitimate as a loopback address, and no parse of the host can
     * tell either from a public one.
     */
    private static function origin(Config $config, string $connection, string $key, string $endpoint): string
    {
        $parts = parse_url($endpoint);

        if ($parts === false || !isset($parts['scheme'], $parts['host']) || $parts['host'] === '') {
            throw S3ConfigurationException::malformedEndpoint($key, 'it needs a scheme and a host');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw S3ConfigurationException::malformedEndpoint($key, 'it carries userinfo');
        }

        if (isset($parts['query']) || isset($parts['fragment'])) {
            throw S3ConfigurationException::malformedEndpoint($key, 'it carries a query or fragment');
        }

        if (($parts['path'] ?? '/') !== '/') {
            throw S3ConfigurationException::malformedEndpoint($key, 'it carries a path');
        }

        $scheme = strtolower($parts['scheme']);
        $host = strtolower($parts['host']);

        if ($scheme === 'http') {
            $plaintextKey = Config::scopedKey('FILESYSTEM_S3_PLAINTEXT', $connection);

            if (!$config->bool($plaintextKey, false)) {
                throw S3ConfigurationException::plaintextEndpoint($key, $plaintextKey);
            }
        } elseif ($scheme !== 'https') {
            throw S3ConfigurationException::malformedEndpoint($key, 'only http and https are supported');
        }

        return $scheme . '://' . $host . (isset($parts['port']) ? ':' . $parts['port'] : '');
    }

    /**
     * One S3 request is one wire attempt at one host: AmpHttpClientFactory
     * installs no retry interceptor, and a request that reached the wrong
     * region is answered with a redirect, which following would replay a
     * request signed for the original host somewhere else.
     *
     * @return array<string, mixed>
     */
    private static function transportOptions(Config $config, string $connection): array
    {
        $timeoutKey = Config::scopedKey('FILESYSTEM_S3_TIMEOUT', $connection);
        $timeout = $config->float($timeoutKey, self::DEFAULT_TIMEOUT);

        if ($timeout <= 0.0) {
            throw S3ConfigurationException::nonPositiveTimeout($timeoutKey);
        }

        return [
            'timeout' => $timeout,
            'max_duration' => $timeout,
            'max_redirects' => 0,
        ];
    }

    /**
     * AsyncAws's own provider order, with one change: every provider that
     * reaches the network is handed $transport.
     * ChainProvider::createDefaultChain() builds ConfigurationProvider
     * with no client, and that provider is the one that calls STS when
     * AWS_ROLE_ARN is set — with no client it constructs a blocking
     * Symfony transport and assumes the role on the worker thread, on
     * first resolution and again on every expiry.
     *
     * CredentialChain holds what they resolve; see it for what is and is
     * not remembered between calls.
     *
     * The shared credentials file, the shared config file and any
     * web-identity or pod-identity token file are read with native
     * blocking calls inside the providers that consult them; that is
     * AsyncAws's resolution and this package does not reimplement it.
     */
    private static function credentialProvider(HttpClientInterface $transport): CredentialProvider
    {
        return new CredentialChain(
            new ConfigurationProvider($transport),
            new WebIdentityProvider(null, null, $transport),
            new IniFileProvider(null, null, $transport),
            new ContainerProvider($transport),
            new InstanceProvider($transport),
        );
    }
}
