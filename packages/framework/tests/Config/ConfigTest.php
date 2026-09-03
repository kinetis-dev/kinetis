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

    /**
     * @return list<array{string}>
     */
    public static function invalidIntegerSyntaxCases(): array
    {
        return [
            'a fraction' => ['1.9'],
            'an exponent' => ['1e3'],
            'leading whitespace' => [' 42'],
            'trailing whitespace' => ['42 '],
            'surrounding whitespace' => [' 42 '],
            'a hex literal' => ['0x1A'],
            'a grouping separator' => ['1_000'],
            'a bare plus sign' => ['+'],
            'a bare minus sign' => ['-'],
            'not numeric at all' => ['abc'],
        ];
    }

    #[DataProvider('invalidIntegerSyntaxCases')]
    public function test_int_or_null_rejects_invalid_integer_syntax(string $raw): void
    {
        $config = new Config(['V' => $raw]);

        $this->expectException(InvalidConfigValueException::class);
        $this->expectExceptionMessage('is not a valid integer');
        $config->intOrNull('V');
    }

    public function test_int_or_null_accepts_leading_zeroes(): void
    {
        $config = new Config(['V' => '007']);

        self::assertSame(7, $config->intOrNull('V'));
    }

    public function test_int_or_null_accepts_a_leading_plus_sign(): void
    {
        $config = new Config(['V' => '+42']);

        self::assertSame(42, $config->intOrNull('V'));
    }

    public function test_int_or_null_accepts_php_int_max_exactly(): void
    {
        $config = new Config(['V' => (string) PHP_INT_MAX]);

        self::assertSame(PHP_INT_MAX, $config->intOrNull('V'));
    }

    public function test_int_or_null_accepts_php_int_min_exactly(): void
    {
        $config = new Config(['V' => (string) PHP_INT_MIN]);

        self::assertSame(PHP_INT_MIN, $config->intOrNull('V'));
    }

    /**
     * @return list<array{string}>
     */
    public static function outOfRangeIntegerCases(): array
    {
        return [
            'one beyond PHP_INT_MAX' => [self::oneBeyondMagnitude((string) PHP_INT_MAX)],
            'one beyond PHP_INT_MIN' => [self::oneBeyondMagnitude((string) PHP_INT_MIN)],
            'a huge digit string' => ['99999999999999999999999999999999'],
        ];
    }

    /**
     * $decimal (PHP_INT_MAX or PHP_INT_MIN, as a string) plus one unit of
     * magnitude — computed via plain string arithmetic, never by casting
     * to (int)/(float), which would itself overflow on the exact
     * platform this exists to test correctly on. "One beyond" a positive
     * bound means one larger; "one beyond" a negative bound (PHP_INT_MIN)
     * means one more negative, i.e. the magnitude also increases by one
     * — a single increment-the-digits routine covers both, since the
     * sign is only reattached afterward.
     */
    private static function oneBeyondMagnitude(string $decimal): string
    {
        $negative = str_starts_with($decimal, '-');
        $digits = $negative ? substr($decimal, 1) : $decimal;
        $incremented = self::incrementDecimalString($digits);

        return $negative ? '-' . $incremented : $incremented;
    }

    private static function incrementDecimalString(string $digits): string
    {
        $result = '';
        $carry = 1;

        for ($i = strlen($digits) - 1; $i >= 0; $i--) {
            $sum = ((int) $digits[$i]) + $carry;
            $carry = intdiv($sum, 10);
            $result = ($sum % 10) . $result;
        }

        return $carry > 0 ? $carry . $result : $result;
    }

    #[DataProvider('outOfRangeIntegerCases')]
    public function test_int_or_null_rejects_values_outside_the_platform_integer_range(string $raw): void
    {
        $config = new Config(['V' => $raw]);

        $this->expectException(InvalidConfigValueException::class);
        $this->expectExceptionMessage('outside the range');
        $config->intOrNull('V');
    }

    public function test_int_or_null_out_of_range_message_is_distinct_from_invalid_syntax(): void
    {
        $config = new Config(['V' => '9223372036854775808']);

        try {
            $config->intOrNull('V');
            self::fail('Expected an InvalidConfigValueException.');
        } catch (InvalidConfigValueException $e) {
            // A syntactically valid integer that is merely too big must
            // not be reported the same way as garbage input.
            self::assertStringNotContainsString('is not a valid integer', $e->getMessage());
            self::assertStringContainsString('syntactically valid integer', $e->getMessage());
        }
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
     * @return list<array{string, float}>
     */
    public static function validFloatSyntaxCases(): array
    {
        return [
            'a plain integer' => ['5', 5.0],
            'ordinary decimal' => ['1.5', 1.5],
            'a leading dot' => ['.5', 0.5],
            'a trailing dot' => ['5.', 5.0],
            'a leading plus sign' => ['+2.5', 2.5],
            'leading zeroes' => ['007.5', 7.5],
            'a positive exponent' => ['1e3', 1000.0],
            'an uppercase exponent' => ['1E3', 1000.0],
            'a negative exponent' => ['1.5e-3', 0.0015],
            'a signed exponent with a plus' => ['1e+3', 1000.0],
            'zero' => ['0', 0.0],
        ];
    }

    #[DataProvider('validFloatSyntaxCases')]
    public function test_float_accepts_every_documented_boundary_form(string $raw, float $expected): void
    {
        $config = new Config(['V' => $raw]);

        self::assertSame($expected, $config->float('V', 1.0));
    }

    /**
     * @return list<array{string}>
     */
    public static function invalidFloatSyntaxCases(): array
    {
        return [
            'leading whitespace' => [' 1.5'],
            'trailing whitespace' => ['1.5 '],
            'a locale decimal comma' => ['1,5'],
            'two decimal points' => ['1.2.3'],
            'an exponent with no digits' => ['1e'],
            'an exponent with no mantissa' => ['e5'],
            'a bare dot' => ['.'],
            'the literal NaN' => ['NaN'],
            'the literal Infinity' => ['Infinity'],
            'the literal INF' => ['INF'],
            'a grouping separator' => ['1_000.5'],
        ];
    }

    #[DataProvider('invalidFloatSyntaxCases')]
    public function test_float_rejects_invalid_syntax(string $raw): void
    {
        $config = new Config(['V' => $raw]);

        $this->expectException(InvalidConfigValueException::class);
        $this->expectExceptionMessage('is not a valid number');
        $config->float('V', 1.0);
    }

    /**
     * @return list<array{string}>
     */
    public static function overflowingFloatCases(): array
    {
        return [
            'positive overflow' => ['1e9999'],
            'negative overflow' => ['-1e9999'],
        ];
    }

    #[DataProvider('overflowingFloatCases')]
    public function test_float_rejects_a_value_that_overflows_to_infinity(string $raw): void
    {
        $config = new Config(['V' => $raw]);

        $this->expectException(InvalidConfigValueException::class);
        $config->float('V', 1.0);
    }

    /**
     * @return list<array{string}>
     */
    public static function underflowingFloatCases(): array
    {
        return [
            'positive underflow' => ['1e-400'],
            'negative underflow' => ['-1e-400'],
        ];
    }

    #[DataProvider('underflowingFloatCases')]
    public function test_float_rejects_a_nonzero_value_that_underflows_to_exactly_zero(string $raw): void
    {
        $config = new Config(['V' => $raw]);

        $this->expectException(InvalidConfigValueException::class);
        $config->float('V', 1.0);
    }

    /**
     * @return list<array{string}>
     */
    public static function genuineZeroFloatCases(): array
    {
        return [
            'plain zero' => ['0'],
            'signed zero' => ['-0'],
            'zero with a fraction' => ['0.0'],
            'zero with a large negative exponent' => ['0e-400'],
        ];
    }

    #[DataProvider('genuineZeroFloatCases')]
    public function test_float_accepts_a_genuine_zero_regardless_of_its_exponent(string $raw): void
    {
        $config = new Config(['V' => $raw]);

        self::assertSame(0.0, $config->float('V', 1.0));
    }

    /**
     * PHP's own string-to-number parsing is locale-independent by
     * design — confirmed directly, not assumed: even under a real
     * comma-decimal LC_NUMERIC locale, `(float) "1.5"` still parses to
     * 1.5 and `is_numeric("1,5")` is still false. This class's own
     * grammar reaches no locale-sensitive function at all (a fixed
     * preg_match() pattern, then a plain (float) cast) — rejecting
     * "1,5" under the process's default "C"/"POSIX" locale alone would
     * not, by itself, prove the same holds after a real LC_NUMERIC
     * change, so this switches to one for real before asserting.
     * Skips when no comma-decimal locale is genuinely installed
     * (confirmed by checking localeconv() actually changed, not just
     * that setlocale() returned a name — musl libc, the standard
     * php:8.4-cli-alpine image's own libc, accepts any locale name
     * without error but never actually changes numeric formatting).
     */
    public function test_float_parsing_is_locale_independent(): void
    {
        $originalLocale = setlocale(LC_NUMERIC, '0');
        self::assertIsString($originalLocale);

        $switched = false;

        foreach (['de_DE.UTF-8', 'de_DE', 'German', 'fr_FR.UTF-8', 'fr_FR', 'French'] as $candidate) {
            if (setlocale(LC_NUMERIC, $candidate) !== false && localeconv()['decimal_point'] === ',') {
                $switched = true;

                break;
            }
        }

        if (!$switched) {
            setlocale(LC_NUMERIC, $originalLocale);
            self::markTestSkipped('No comma-decimal LC_NUMERIC locale is genuinely installed in this environment.');
        }

        try {
            $config = new Config(['DOT' => '1.5', 'COMMA' => '1,5']);

            self::assertSame(1.5, $config->float('DOT', 0.0));

            $this->expectException(InvalidConfigValueException::class);
            $config->float('COMMA', 0.0);
        } finally {
            setlocale(LC_NUMERIC, $originalLocale);
        }
    }

    public function test_float_out_of_range_message_is_distinct_from_invalid_syntax(): void
    {
        $config = new Config(['V' => '1e9999']);

        try {
            $config->float('V', 1.0);
            self::fail('Expected an InvalidConfigValueException.');
        } catch (InvalidConfigValueException $e) {
            self::assertStringNotContainsString('is not a valid number', $e->getMessage());
            self::assertStringContainsString('syntactically valid number', $e->getMessage());
        }
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
