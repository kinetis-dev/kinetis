<?php

declare(strict_types=1);

namespace Kinetis\Auth\Tests;

use Kinetis\Auth\TokenGenerator;
use PHPUnit\Framework\TestCase;

final class TokenGeneratorTest extends TestCase
{
    public function test_generates_a_hex_string_twice_the_requested_byte_length(): void
    {
        $token = TokenGenerator::generate(32);

        self::assertSame(64, strlen($token));
        self::assertMatchesRegularExpression('/^[0-9a-f]+$/', $token);
    }

    public function test_respects_a_custom_byte_length(): void
    {
        $token = TokenGenerator::generate(16);

        self::assertSame(32, strlen($token));
    }

    public function test_two_generated_tokens_are_not_the_same(): void
    {
        self::assertNotSame(TokenGenerator::generate(), TokenGenerator::generate());
    }
}
