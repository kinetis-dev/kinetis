<?php

declare(strict_types=1);

namespace Kinetis\Mailer\Tests;

use Kinetis\Mailer\Exception\MailerConfigurationException;
use Kinetis\Mailer\Registry\TransportRegistry;
use Kinetis\Mailer\Tests\Doubles\FakeApiTransport;
use Kinetis\Mailer\Tests\Doubles\RecordingHttpClient;
use Kinetis\Mailer\Tests\Doubles\RegistryFixture;
use Kinetis\Mailer\Tests\Doubles\StubTransportFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;

/**
 * The boundary around the assembly, driven over fixture registries whose
 * stub factory misbehaves in ways no official factory does.
 */
final class MailerAssemblerTest extends TestCase
{
    use AssemblesMailers;
    use RendersThrowables;

    private const string SECRET = 'sw0rdf1shSENTINEL';

    private const string API_DSN = RegistryFixture::API_SCHEME . '://KEY@default';

    #[\Override]
    protected function setUp(): void
    {
        StubTransportFactory::reset();
    }

    #[\Override]
    protected function tearDown(): void
    {
        StubTransportFactory::reset();
    }

    public function test_the_client_given_is_the_one_every_factory_is_constructed_with(): void
    {
        $recorder = new RecordingHttpClient();
        StubTransportFactory::$create = static fn (): FakeApiTransport => new FakeApiTransport(new MockHttpClient());

        $this->build(self::API_DSN, RegistryFixture::of(RegistryFixture::stubbedApi()), client: $recorder);

        self::assertSame($recorder, StubTransportFactory::$lastClient);
    }

    public function test_a_refusal_the_package_already_built_passes_through_unwrapped(): void
    {
        // Judged before any factory runs, over the production registry:
        // the reason survives rather than collapsing into ASSEMBLY_FAILED.
        $this->expectRejection('carrierpigeon://default', MailerConfigurationException::UNSUPPORTED_SCHEME, TransportRegistry::supported());
    }

    public function test_an_unexpected_throwable_from_a_factory_is_replaced_safely(): void
    {
        StubTransportFactory::$create = static fn (): never
            => throw new \Error('a vendor bug quoting smtp://admin:' . self::SECRET . '@mail.example.com');

        $exception = $this->assertNothingLeaks(self::API_DSN);

        self::assertStringContainsString(MailerConfigurationException::CONSTRUCTION_FAILED, $exception->getMessage());
    }

    public function test_a_supports_call_that_throws_is_replaced_safely(): void
    {
        StubTransportFactory::$supports = static fn (): never
            => throw new \RuntimeException('supports quoted ' . self::SECRET);

        $exception = $this->assertNothingLeaks(RegistryFixture::API_SCHEME . '://' . self::SECRET . '@default');

        self::assertStringContainsString(MailerConfigurationException::ASSEMBLY_FAILED, $exception->getMessage());
    }

    public function test_a_wrong_class_transport_retaining_a_sentinel_leaks_nothing(): void
    {
        StubTransportFactory::$create = static fn (): SentinelTransport => new SentinelTransport(self::SECRET);

        $exception = $this->assertNothingLeaks(self::API_DSN);

        self::assertStringContainsString(MailerConfigurationException::UNPROVABLE_FAMILY, $exception->getMessage());
    }

    private function assertNothingLeaks(#[\SensitiveParameter] string $dsn): MailerConfigurationException
    {
        try {
            $this->build($dsn, RegistryFixture::of(RegistryFixture::stubbedApi()));
        } catch (MailerConfigurationException $e) {
            self::assertNull($e->getPrevious(), 'no cause is retained');
            $this->assertNoSentinelSurvives($e, [self::SECRET]);

            return $e;
        }

        self::fail('The DSN was accepted.');
    }
}
