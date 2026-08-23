<?php

declare(strict_types=1);

namespace Kinetis\Auth\Tests;

use Kinetis\Auth\PasswordHasher;
use PHPUnit\Framework\TestCase;

final class PasswordHasherTest extends TestCase
{
    public function test_a_hashed_password_verifies_against_the_original(): void
    {
        $hash = PasswordHasher::hash('correct horse battery staple');

        self::assertTrue(PasswordHasher::verify('correct horse battery staple', $hash));
    }

    public function test_a_wrong_password_does_not_verify(): void
    {
        $hash = PasswordHasher::hash('correct horse battery staple');

        self::assertFalse(PasswordHasher::verify('wrong password', $hash));
    }

    public function test_two_hashes_of_the_same_password_are_not_identical(): void
    {
        // A real per-hash salt, not a fixed one — the whole reason a
        // hash isn't just compared for string equality.
        self::assertNotSame(
            PasswordHasher::hash('correct horse battery staple'),
            PasswordHasher::hash('correct horse battery staple'),
        );
    }

    public function test_needs_rehash_is_true_for_a_hash_produced_with_a_weaker_cost(): void
    {
        $weakHash = password_hash('correct horse battery staple', PASSWORD_BCRYPT, ['cost' => 4]);

        self::assertTrue(PasswordHasher::needsRehash($weakHash));
    }

    public function test_needs_rehash_is_false_for_a_hash_this_class_just_produced(): void
    {
        $hash = PasswordHasher::hash('correct horse battery staple');

        self::assertFalse(PasswordHasher::needsRehash($hash));
    }
}
