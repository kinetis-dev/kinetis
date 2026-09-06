<?php

declare(strict_types=1);

namespace Kinetis\Mailer\Tests;

use Kinetis\Mailer\ConnectionName;
use Kinetis\Mailer\Exception\MailerConfigurationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ConnectionNameTest extends TestCase
{
    /**
     * @return iterable<string, array{0: string}>
     */
    public static function acceptedNames(): iterable
    {
        yield 'the default' => ['default'];
        yield 'one word' => ['transactional'];
        yield 'digits' => ['relay2'];
        yield 'underscore-joined segments' => ['ops_alerts'];
        yield 'the longest allowed' => [str_repeat('a', ConnectionName::MAX_LENGTH)];
    }

    #[DataProvider('acceptedNames')]
    public function test_a_canonical_name_is_returned_unchanged(string $name): void
    {
        self::assertSame($name, ConnectionName::validated($name));
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function refusedNames(): iterable
    {
        yield 'empty' => [''];
        yield 'one byte too long' => [str_repeat('a', ConnectionName::MAX_LENGTH + 1)];
        yield 'uppercase' => ['Transactional'];
        yield 'a leading underscore' => ['_alerts'];
        yield 'a trailing underscore' => ['alerts_'];
        yield 'a doubled underscore' => ['ops__alerts'];
        yield 'a hyphen' => ['ops-alerts'];
        yield 'a dot' => ['ops.alerts'];
        yield 'a path separator' => ['../secrets'];
        yield 'a backslash' => ['ops\\alerts'];
        yield 'a space' => ['ops alerts'];
        yield 'a NUL byte' => ["ops\0alerts"];
        yield 'a newline' => ["ops\nalerts"];
        yield 'a key fragment' => ['dsn_x=1'];
    }

    #[DataProvider('refusedNames')]
    public function test_a_name_outside_the_grammar_is_refused(string $name): void
    {
        $this->expectException(MailerConfigurationException::class);

        ConnectionName::validated($name);
    }

    public function test_the_refusal_never_echoes_the_name_back(): void
    {
        try {
            ConnectionName::validated("../MAILER_DSN\0");
        } catch (MailerConfigurationException $e) {
            self::assertStringNotContainsString('..', $e->getMessage());

            return;
        }

        self::fail('The name was accepted.');
    }
}
