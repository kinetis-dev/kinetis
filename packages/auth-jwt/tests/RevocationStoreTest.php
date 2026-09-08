<?php

declare(strict_types=1);

namespace Kinetis\AuthJwt\Tests;

use Kinetis\AuthJwt\Exception\RevocationUnavailableException;
use Kinetis\AuthJwt\JwtUser;
use Kinetis\AuthJwt\RevocationStore;
use Kinetis\AuthJwt\Tests\Fixtures\FailingSimpleCache;
use Kinetis\AuthJwt\Tests\Fixtures\InMemorySimpleCache;
use Kinetis\AuthJwt\Tests\Fixtures\RecordingSimpleCache;
use Kinetis\SimpleCache\NullSimpleCache;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RevocationStoreTest extends TestCase
{
    /**
     * A conforming PSR-16 cache may report a failed write by returning
     * false rather than throwing — this must never be silently accepted
     * as a successful revocation, per revoke()'s own docblock.
     */
    public function test_revoke_throws_when_the_cache_write_fails(): void
    {
        $store = new RevocationStore(new FailingSimpleCache());
        $secretJti = 'super-secret-jti-must-never-leak-into-a-message';

        try {
            $store->revoke($secretJti, 60);
            self::fail('Expected a RevocationUnavailableException.');
        } catch (RevocationUnavailableException $e) {
            self::assertStringNotContainsString($secretJti, $e->getMessage());
        }
    }

    /**
     * revokeToken() is the ergonomic wrapper real callers use — proving
     * the propagation here, not just through revoke() directly, closes
     * the actual real-world call path.
     */
    public function test_revoke_token_throws_when_the_cache_write_fails(): void
    {
        $store = new RevocationStore(new FailingSimpleCache());
        $claims = (object) ['sub' => 'user-42', 'jti' => 'super-secret-jti-must-never-leak', 'exp' => time() + 3600];

        try {
            $store->revokeToken(new JwtUser($claims));
            self::fail('Expected a RevocationUnavailableException.');
        } catch (RevocationUnavailableException $e) {
            self::assertStringNotContainsString('super-secret-jti-must-never-leak', $e->getMessage());
        }
    }

    public function test_a_token_is_not_revoked_by_default(): void
    {
        $store = new RevocationStore(new InMemorySimpleCache());

        self::assertFalse($store->isRevoked('some-jti'));
    }

    public function test_revoke_marks_a_jti_as_revoked(): void
    {
        $store = new RevocationStore(new InMemorySimpleCache());

        $store->revoke('some-jti', 60);

        self::assertTrue($store->isRevoked('some-jti'));
    }

    public function test_revoking_one_jti_does_not_affect_another(): void
    {
        $store = new RevocationStore(new InMemorySimpleCache());

        $store->revoke('jti-a', 60);

        self::assertTrue($store->isRevoked('jti-a'));
        self::assertFalse($store->isRevoked('jti-b'));
    }

    public function test_revoke_rejects_a_zero_ttl(): void
    {
        $store = new RevocationStore(new InMemorySimpleCache());

        $this->expectException(RevocationUnavailableException::class);

        $store->revoke('some-jti', 0);
    }

    public function test_revoke_rejects_a_negative_ttl(): void
    {
        $store = new RevocationStore(new InMemorySimpleCache());

        $this->expectException(RevocationUnavailableException::class);

        $store->revoke('some-jti', -100);
    }

    public function test_revoke_with_a_null_ttl_revokes_indefinitely(): void
    {
        $store = new RevocationStore(new InMemorySimpleCache());

        $store->revoke('some-jti', null);

        self::assertTrue($store->isRevoked('some-jti'));
    }

    public function test_revoke_token_reads_the_jti_and_exp_claims(): void
    {
        $store = new RevocationStore(new InMemorySimpleCache());
        $user = new JwtUser((object) ['sub' => 'user-42', 'jti' => 'the-jti', 'exp' => time() + 60]);

        $store->revokeToken($user);

        self::assertTrue($store->isRevoked('the-jti'));
    }

    public function test_revoke_token_revokes_indefinitely_when_the_token_has_no_exp(): void
    {
        $store = new RevocationStore(new InMemorySimpleCache());
        $user = new JwtUser((object) ['sub' => 'user-42', 'jti' => 'the-jti']);

        $store->revokeToken($user);

        self::assertTrue($store->isRevoked('the-jti'));
    }

    public function test_revoke_token_throws_when_the_token_has_no_jti(): void
    {
        $store = new RevocationStore(new InMemorySimpleCache());
        $user = new JwtUser((object) ['sub' => 'user-42']);

        $this->expectException(RevocationUnavailableException::class);

        $store->revokeToken($user);
    }

    public function test_revoke_token_throws_when_the_jti_is_an_empty_string(): void
    {
        $store = new RevocationStore(new InMemorySimpleCache());
        $user = new JwtUser((object) ['sub' => 'user-42', 'jti' => '']);

        $this->expectException(RevocationUnavailableException::class);

        $store->revokeToken($user);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function invalidExpValues(): iterable
    {
        yield 'numeric string' => ['9999999999'];
        yield 'float' => [9999999999.0];
        yield 'exponent string' => ['1e10'];
        yield 'bool' => [true];
    }

    #[DataProvider('invalidExpValues')]
    public function test_revoke_token_throws_when_exp_is_present_but_not_a_plain_integer(mixed $exp): void
    {
        $store = new RevocationStore(new InMemorySimpleCache());
        $user = new JwtUser((object) ['sub' => 'user-42', 'jti' => 'the-jti', 'exp' => $exp]);

        $this->expectException(RevocationUnavailableException::class);

        $store->revokeToken($user);
    }

    public function test_revoke_token_skips_the_write_when_exp_is_already_in_the_past(): void
    {
        $cache = new RecordingSimpleCache();
        $store = new RevocationStore($cache);
        $user = new JwtUser((object) ['sub' => 'user-42', 'jti' => 'the-jti', 'exp' => time() - 10]);

        // Must not throw, even though revoke() itself would reject the
        // resulting non-positive TTL — the already-expired case is
        // handled before revoke() is ever called.
        $store->revokeToken($user);

        self::assertFalse($store->isRevoked('the-jti'));
    }

    public function test_construction_over_a_null_cache_throws_instead_of_silently_not_revoking(): void
    {
        $this->expectException(RevocationUnavailableException::class);

        new RevocationStore(new NullSimpleCache());
    }
}
