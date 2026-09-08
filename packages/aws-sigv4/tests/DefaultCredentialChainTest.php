<?php

declare(strict_types=1);

namespace Kinetis\AwsSigV4\Tests;

use AsyncAws\Core\Configuration;
use AsyncAws\Core\Credentials\ConfigurationProvider;
use AsyncAws\Core\Credentials\ContainerProvider;
use AsyncAws\Core\Credentials\CredentialProvider;
use AsyncAws\Core\Credentials\Credentials;
use AsyncAws\Core\Credentials\IniFileProvider;
use AsyncAws\Core\Credentials\InstanceProvider;
use AsyncAws\Core\Credentials\WebIdentityProvider;
use Kinetis\AwsSigV4\CredentialChain;
use Kinetis\AwsSigV4\SigV4SigningClient;
use Kinetis\AwsSigV4\Tests\Support\CountingCredentialProvider;
use Kinetis\AwsSigV4\Tests\Support\FixedCredentialProvider;
use Kinetis\AwsSigV4\Tests\Support\RecordingTransport;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * The default chain: which providers it holds, what they resolve
 * credentials over, and what it keeps between calls.
 */
final class DefaultCredentialChainTest extends TestCase
{
    /**
     * @return list<object>
     */
    private static function providersOf(CredentialChain $chain): array
    {
        /** @var list<object> $providers */
        $providers = new ReflectionProperty(CredentialChain::class, 'providers')->getValue($chain);

        return $providers;
    }

    private static function chainOf(SigV4SigningClient $client): CredentialChain
    {
        $provider = new ReflectionProperty(SigV4SigningClient::class, 'credentialProvider')->getValue($client);

        self::assertInstanceOf(CredentialChain::class, $provider);

        return $provider;
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

    /**
     * No provider in the chain may reach AWS on a transport this package
     * did not supply — one left without it builds a blocking Symfony
     * client and assumes its role on the worker thread. Reflection is
     * what can see that: the providers expose no accessor, and making
     * one call resolve would need real AWS environment state.
     */
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

    /**
     * A provider that answers null, and one that answers with
     * credentials that have already expired, are both unusable. The
     * chain passes over each and keeps going in the same call, so an
     * environment where the earlier providers say nothing useful still
     * resolves from a later one.
     */
    public function test_a_null_or_expired_answer_does_not_stop_a_later_provider_being_used(): void
    {
        $fresh = new Credentials('AKIDEXAMPLE', 'secret', null, new \DateTimeImmutable('+1 hour'));

        $chain = self::chainWith(
            new CountingCredentialProvider(null),
            new CountingCredentialProvider(
                new Credentials('EXPIREDKEY', 'secret', null, new \DateTimeImmutable('-1 second')),
            ),
            new CountingCredentialProvider($fresh),
        );

        self::assertSame($fresh, $chain->getCredentials(Configuration::create([])));
    }

    /**
     * The first unexpired answer is held: the chain runs once and every
     * later call is served from it.
     */
    public function test_an_unexpired_credential_is_reused_without_running_the_chain_again(): void
    {
        $provider = new CountingCredentialProvider(FixedCredentialProvider::example()->getCredentials(
            Configuration::create([]),
        ));
        $chain = self::chainWith($provider);

        $chain->getCredentials(Configuration::create([]));
        $chain->getCredentials(Configuration::create([]));

        self::assertSame(1, $provider->calls);
    }

    /**
     * A call that resolves nothing caches nothing. Caching a complete
     * miss would turn one transient ECS, IMDS or token-file failure into
     * a client that never resolves credentials again for the rest of the
     * worker's life.
     */
    public function test_a_complete_miss_is_not_cached(): void
    {
        $provider = new CountingCredentialProvider(null);
        $chain = self::chainWith($provider);

        self::assertNull($chain->getCredentials(Configuration::create([])));

        $provider->answer = new Credentials('AKIDEXAMPLE', 'secret');

        self::assertSame($provider->answer, $chain->getCredentials(Configuration::create([])));
        self::assertSame(2, $provider->calls);
    }

    /**
     * Held credentials that expire send the next call back down the
     * chain rather than being handed out past their lifetime.
     */
    public function test_an_expired_held_credential_is_resolved_again(): void
    {
        $provider = new CountingCredentialProvider(
            new Credentials('AKIDEXAMPLE', 'secret', null, new \DateTimeImmutable('+1 second')),
        );
        $chain = self::chainWith($provider);

        $chain->getCredentials(Configuration::create([]));

        $provider->answer = new Credentials('SECONDKEY', 'secret');
        new ReflectionProperty(CredentialChain::class, 'held')->setValue(
            $chain,
            new Credentials('AKIDEXAMPLE', 'secret', null, new \DateTimeImmutable('-1 second')),
        );

        self::assertSame($provider->answer, $chain->getCredentials(Configuration::create([])));
    }

    /**
     * The chain's providers are AsyncAws's own and need real AWS
     * environment state to answer, so its holding and pass-over rules
     * are exercised against providers this suite controls.
     */
    private static function chainWith(CredentialProvider ...$providers): CredentialChain
    {
        $chain = new CredentialChain(new RecordingTransport()->asTransport());

        new ReflectionProperty(CredentialChain::class, 'providers')->setValue($chain, array_values($providers));

        return $chain;
    }
}
