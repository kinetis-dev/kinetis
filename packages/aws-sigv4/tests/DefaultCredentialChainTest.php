<?php

declare(strict_types=1);

namespace Kinetis\AwsSigV4\Tests;

use AsyncAws\Core\Credentials\CacheProvider;
use AsyncAws\Core\Credentials\ChainProvider;
use AsyncAws\Core\Credentials\ConfigurationProvider;
use AsyncAws\Core\Credentials\ContainerProvider;
use AsyncAws\Core\Credentials\IniFileProvider;
use AsyncAws\Core\Credentials\InstanceProvider;
use AsyncAws\Core\Credentials\WebIdentityProvider;
use Kinetis\AwsSigV4\SigV4SigningClient;
use Kinetis\AwsSigV4\Tests\Support\FixedCredentialProvider;
use Kinetis\AwsSigV4\Tests\Support\RecordingTransport;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * The default credential chain is vendor wiring, and the invariant it
 * has to hold is that no provider in it reaches AWS on a transport this
 * class did not supply — a provider left without one builds a blocking
 * Symfony client and assumes its role on the worker thread. Reflection
 * is what can see that: the providers expose no accessor, and making one
 * call resolve would need real AWS environment state.
 */
final class DefaultCredentialChainTest extends TestCase
{
    private static function chainOf(SigV4SigningClient $client): ChainProvider
    {
        $provider = new ReflectionProperty(SigV4SigningClient::class, 'credentialProvider')->getValue($client);

        self::assertInstanceOf(CacheProvider::class, $provider);

        $decorated = new ReflectionProperty(CacheProvider::class, 'decorated')->getValue($provider);

        self::assertInstanceOf(ChainProvider::class, $decorated);

        return $decorated;
    }

    /**
     * @return list<object>
     */
    private static function providersOf(ChainProvider $chain): array
    {
        /** @var iterable<object> $providers */
        $providers = new ReflectionProperty(ChainProvider::class, 'providers')->getValue($chain);

        return array_values([...$providers]);
    }

    private function client(RecordingTransport $transport): SigV4SigningClient
    {
        return new SigV4SigningClient(
            'https://example.amazonaws.com',
            'us-east-1',
            'service',
            null,
            null,
            $transport->asTransport(),
        );
    }

    public function test_the_default_chain_holds_the_standard_providers_in_the_standard_order(): void
    {
        $providers = self::providersOf(self::chainOf($this->client(new RecordingTransport())));

        self::assertSame([
            ConfigurationProvider::class,
            WebIdentityProvider::class,
            IniFileProvider::class,
            ContainerProvider::class,
            InstanceProvider::class,
        ], array_map(static fn (object $provider): string => $provider::class, $providers));
    }

    public function test_every_provider_that_reaches_the_network_owns_the_injected_transport(): void
    {
        $transport = new RecordingTransport();
        $signed = $transport->asTransport();

        $client = new SigV4SigningClient(
            'https://example.amazonaws.com',
            'us-east-1',
            'service',
            null,
            null,
            $signed,
        );

        foreach (self::providersOf(self::chainOf($client)) as $provider) {
            self::assertSame(
                $signed,
                new ReflectionProperty($provider::class, 'httpClient')->getValue($provider),
                $provider::class . ' must resolve credentials over the injected transport.',
            );
        }
    }

    public function test_a_supplied_credential_provider_replaces_the_chain_entirely(): void
    {
        $provider = FixedCredentialProvider::example();

        $client = new SigV4SigningClient(
            'https://example.amazonaws.com',
            'us-east-1',
            'service',
            $provider,
            null,
            new RecordingTransport()->asTransport(),
        );

        self::assertSame(
            $provider,
            new ReflectionProperty(SigV4SigningClient::class, 'credentialProvider')->getValue($client),
        );
    }
}
