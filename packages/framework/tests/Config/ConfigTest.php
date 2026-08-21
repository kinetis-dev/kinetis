<?php

declare(strict_types=1);

namespace Kinetis\Tests\Config;

use Kinetis\Config\Config;
use Kinetis\Config\Exception\InvalidConfigValueException;
use Kinetis\Config\Exception\MissingConfigException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    public function test_get_returns_the_raw_string_value(): void
    {
        $config = new Config(['DB_HOST' => 'db.internal']);

        self::assertSame('db.internal', $config->get('DB_HOST'));
    }

    public function test_get_returns_the_default_when_missing(): void
    {
        $config = new Config([]);

        self::assertNull($config->get('DB_HOST'));
        self::assertSame('localhost', $config->get('DB_HOST', 'localhost'));
    }

    public function test_string_returns_the_default_when_missing(): void
    {
        $config = new Config([]);

        self::assertSame('localhost', $config->string('DB_HOST', 'localhost'));
    }

    public function test_int_casts_present_values_and_falls_back_to_the_default(): void
    {
        $config = new Config(['DB_PORT' => '3306']);

        self::assertSame(3306, $config->int('DB_PORT', 5432));
        self::assertSame(5432, $config->int('MISSING_PORT', 5432));
    }

    /**
     * The same "REDIS_HOST= is how a value gets turned off" convention
     * already established elsewhere — an explicitly-empty value is
     * treated the same as an absent one, not as "configured to zero."
     */
    public function test_int_treats_an_explicitly_empty_value_as_unset(): void
    {
        $config = new Config(['DB_PORT' => '']);

        self::assertSame(5432, $config->int('DB_PORT', 5432));
    }

    public function test_int_throws_on_a_non_numeric_value(): void
    {
        $config = new Config(['MAX_BODY_SIZE' => '5abc']);

        $this->expectException(InvalidConfigValueException::class);
        $this->expectExceptionMessage('Config value "MAX_BODY_SIZE" is not a valid integer, got "5abc".');
        $config->int('MAX_BODY_SIZE', 2_097_152);
    }

    public function test_int_or_null_returns_null_when_unset_or_empty(): void
    {
        $config = new Config(['DB_CONNECT_TIMEOUT' => '']);

        self::assertNull($config->intOrNull('DB_CONNECT_TIMEOUT'));
        self::assertNull($config->intOrNull('MISSING'));
    }

    public function test_int_or_null_returns_the_parsed_value_when_present(): void
    {
        $config = new Config(['DB_CONNECT_TIMEOUT' => '5']);

        self::assertSame(5, $config->intOrNull('DB_CONNECT_TIMEOUT'));
    }

    public function test_int_or_null_throws_on_a_non_numeric_value(): void
    {
        $config = new Config(['DB_CONNECT_TIMEOUT' => '5abc']);

        $this->expectException(InvalidConfigValueException::class);
        $config->intOrNull('DB_CONNECT_TIMEOUT');
    }

    public function test_float_casts_present_values_and_falls_back_to_the_default(): void
    {
        $config = new Config(['THRESHOLD' => '0.75']);

        self::assertSame(0.75, $config->float('THRESHOLD', 1.0));
        self::assertSame(1.0, $config->float('MISSING', 1.0));
    }

    public function test_float_treats_an_explicitly_empty_value_as_unset(): void
    {
        $config = new Config(['THRESHOLD' => '']);

        self::assertSame(1.0, $config->float('THRESHOLD', 1.0));
    }

    public function test_float_throws_on_a_non_numeric_value(): void
    {
        $config = new Config(['OTEL_TRACES_SAMPLER_ARG' => 'high']);

        $this->expectException(InvalidConfigValueException::class);
        $this->expectExceptionMessage('Config value "OTEL_TRACES_SAMPLER_ARG" is not a valid number, got "high".');
        $config->float('OTEL_TRACES_SAMPLER_ARG', 1.0);
    }

    /**
     * @return list<array{string, bool}>
     */
    public static function boolCases(): array
    {
        return [
            ['true', true],
            ['1', true],
            ['false', false],
            ['0', false],
        ];
    }

    #[DataProvider('boolCases')]
    public function test_bool_casts_common_truthy_and_falsy_string_forms(string $raw, bool $expected): void
    {
        $config = new Config(['DEBUG' => $raw]);

        self::assertSame($expected, $config->bool('DEBUG', !$expected));
    }

    public function test_bool_falls_back_to_the_default_when_missing(): void
    {
        $config = new Config([]);

        self::assertTrue($config->bool('DEBUG', true));
        self::assertFalse($config->bool('DEBUG', false));
    }

    public function test_bool_treats_an_explicitly_empty_value_as_unset(): void
    {
        $config = new Config(['DEBUG' => '']);

        self::assertTrue($config->bool('DEBUG', true));
    }

    public function test_bool_throws_on_an_unrecognized_value(): void
    {
        $config = new Config(['DEBUG' => 'purple']);

        $this->expectException(InvalidConfigValueException::class);
        $this->expectExceptionMessage(
            'Config value "DEBUG" is not a recognized boolean, got "purple". '
            . 'Use "true"/"false", "1"/"0", "on"/"off", or "yes"/"no".',
        );
        $config->bool('DEBUG', false);
    }

    public function test_required_returns_the_value_when_present(): void
    {
        $config = new Config(['DB_PASSWORD' => 'secret']);

        self::assertSame('secret', $config->required('DB_PASSWORD'));
    }

    public function test_required_throws_a_clear_error_when_missing(): void
    {
        $config = new Config([]);

        $this->expectException(MissingConfigException::class);
        $this->expectExceptionMessage('DB_PASSWORD');
        $config->required('DB_PASSWORD');
    }

    public function test_from_environment_snapshots_a_real_environment_variable(): void
    {
        putenv('KINETIS_CONFIG_TEST_VAR=snapshot-value');

        try {
            $config = Config::fromEnvironment();

            self::assertSame('snapshot-value', $config->get('KINETIS_CONFIG_TEST_VAR'));
        } finally {
            putenv('KINETIS_CONFIG_TEST_VAR');
        }
    }

    public function test_scoped_key_returns_the_plain_key_for_the_default_connection(): void
    {
        self::assertSame('REDIS_HOST', Config::scopedKey('REDIS_HOST'));
        self::assertSame('REDIS_HOST', Config::scopedKey('REDIS_HOST', 'default'));
    }

    public function test_scoped_key_inserts_the_uppercased_connection_name_after_the_prefix(): void
    {
        self::assertSame('REDIS_CACHE2_HOST', Config::scopedKey('REDIS_HOST', 'cache2'));
        self::assertSame('DB_DB2_PASSWORD', Config::scopedKey('DB_PASSWORD', 'db2'));
    }

    public function test_scoped_key_appends_the_connection_name_when_the_key_has_no_underscore(): void
    {
        self::assertSame('KEY_CACHE2', Config::scopedKey('KEY', 'cache2'));
    }
}
