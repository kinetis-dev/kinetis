<?php

declare(strict_types=1);

namespace Kinetis\AuthJwt\Tests;

use Kinetis\AuthJwt\JwtUser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;
use stdClass;

final class JwtUserTest extends TestCase
{
    private function claims(array $data): stdClass
    {
        return (object) $data;
    }

    public function test_id_reads_the_subject_claim(): void
    {
        $user = new JwtUser($this->claims(['sub' => 'user-42']));

        self::assertSame('user-42', $user->id());
    }

    public function test_id_throws_when_the_subject_claim_is_missing(): void
    {
        $user = new JwtUser($this->claims(['role' => 'admin']));

        $this->expectException(UnexpectedValueException::class);

        $user->id();
    }

    /**
     * A subject is one canonical non-empty string, so a claims object
     * carrying anything else has no id to hand back. JwtAuthMiddleware
     * rejects such a token with a 401 before a JwtUser is ever
     * registered; constructing one directly is the only way to reach
     * this at all.
     */
    #[DataProvider('nonCanonicalSubjects')]
    public function test_id_throws_for_a_subject_that_is_not_a_non_empty_string(mixed $sub): void
    {
        $user = new JwtUser($this->claims(['sub' => $sub]));

        $this->expectException(UnexpectedValueException::class);

        $user->id();
    }

    public static function nonCanonicalSubjects(): iterable
    {
        yield 'an integer' => [42];
        yield 'an empty string' => [''];
        yield 'a float' => [42.5];
        yield 'null' => [null];
        yield 'a boolean' => [true];
    }

    public function test_claim_reads_an_arbitrary_claim(): void
    {
        $user = new JwtUser($this->claims(['sub' => 'user-42', 'role' => 'admin']));

        self::assertSame('admin', $user->claim('role'));
    }

    public function test_claim_returns_null_for_a_missing_claim(): void
    {
        $user = new JwtUser($this->claims(['sub' => 'user-42']));

        self::assertNull($user->claim('role'));
    }

    public function test_claims_returns_the_full_decoded_object(): void
    {
        $claims = $this->claims(['sub' => 'user-42', 'role' => 'admin']);
        $user = new JwtUser($claims);

        self::assertSame($claims, $user->claims());
    }
}
