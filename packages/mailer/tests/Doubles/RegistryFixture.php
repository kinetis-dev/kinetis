<?php

declare(strict_types=1);

namespace Kinetis\Mailer\Tests\Doubles;

use Kinetis\Mailer\Registry\CredentialMode;
use Kinetis\Mailer\Registry\HostShape;
use Kinetis\Mailer\Registry\TransportEntry;
use Kinetis\Mailer\Registry\TransportKind;
use Kinetis\Mailer\Registry\TransportRegistry;
use Symfony\Component\Mailer\Transport\NullTransport;

/**
 * Builds the fixture registries the refusal tests need: entries whose
 * factory is {@see StubTransportFactory}, so a test can make a factory
 * decline, throw, or return the wrong object — behaviour no official
 * factory has. Every admitted scheme is tested against its real factory
 * instead; nothing here mirrors the production table.
 */
final class RegistryFixture
{
    public const string API_SCHEME = 'sendgrid+api';

    public const string API_PACKAGE = 'symfony/sendgrid-mailer';

    public static function of(TransportEntry ...$entries): TransportRegistry
    {
        return new TransportRegistry($entries);
    }

    /**
     * An API entry built by the stub factory, resolving to
     * {@see FakeApiTransport} unless a test names another class.
     *
     * @param class-string       $factoryClass
     * @param list<class-string> $transportClasses
     */
    public static function stubbedApi(
        string $factoryClass = StubTransportFactory::class,
        array $transportClasses = [FakeApiTransport::class],
    ): TransportEntry {
        return new TransportEntry(
            self::API_SCHEME,
            TransportKind::Api,
            $factoryClass,
            $transportClasses,
            self::API_PACKAGE,
            CredentialMode::UserOnly,
            HostShape::DefaultOrHost,
        );
    }

    /**
     * The discard scheme built by the stub factory, so a test can count
     * how many leaves were constructed.
     */
    public static function stubbedDiscard(): TransportEntry
    {
        return new TransportEntry(
            'null',
            TransportKind::Discard,
            StubTransportFactory::class,
            [NullTransport::class],
            'symfony/mailer',
            CredentialMode::None,
            HostShape::LiteralNull,
        );
    }

    /**
     * A production entry, for a fixture that mixes one stubbed scheme
     * with real ones.
     */
    public static function production(string $scheme): TransportEntry
    {
        return TransportRegistry::supported()->get($scheme)
            ?? throw new \LogicException("{$scheme} is not in the supported registry.");
    }
}
