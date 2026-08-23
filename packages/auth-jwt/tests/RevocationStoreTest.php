<?php

declare(strict_types=1);

namespace Kinetis\AuthJwt\Tests;

use Kinetis\AuthJwt\Exception\RevocationUnavailableException;
use Kinetis\AuthJwt\JwtUser;
use Kinetis\AuthJwt\RevocationStore;
use Kinetis\AuthJwt\Tests\Fixtures\InMemorySimpleCache;
use Kinetis\SimpleCache\NullSimpleCache;
use PHPUnit\Framework\TestCase;

final class RevocationStoreTest extends TestCase
{
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

    public function test_a_negative_ttl_is_clamped_to_zero_rather_than_erroring(): void
    {
        $store = new RevocationStore(new InMemorySimpleCache());

        $store->revoke('some-jti', -100);

        // Not asserting the *value* here, deliberately: a 0-second entry
        // may or may not still read back as revoked depending on timing
        // precision — the only real requirement is that a negative TTL
        // doesn't throw.
        self::assertIsBool($store->isRevoked('some-jti'));
    }

    public function test_revoke_token_reads_the_jti_and_exp_claims(): void
    {
        $store = new RevocationStore(new InMemorySimpleCache());
        $user = new JwtUser((object) ['sub' => 'user-42', 'jti' => 'the-jti', 'exp' => time() + 60]);

        $store->revokeToken($user);

        self::assertTrue($store->isRevoked('the-jti'));
    }

    public function test_revoke_token_is_a_no_op_when_the_token_has_no_jti(): void
    {
        $store = new RevocationStore(new InMemorySimpleCache());
        $user = new JwtUser((object) ['sub' => 'user-42']);

        // Must not throw.
        $store->revokeToken($user);

        self::assertTrue(true);
    }

    public function test_a_user_is_not_revoked_by_default(): void
    {
        $store = new RevocationStore(new InMemorySimpleCache());

        self::assertFalse($store->isRevokedForUser('user-42', time()));
    }

    public function test_revoke_all_for_user_rejects_a_token_issued_before_the_call(): void
    {
        $store = new RevocationStore(new InMemorySimpleCache());
        $issuedAt = time() - 10;

        $store->revokeAllForUser('user-42', 60);

        self::assertTrue($store->isRevokedForUser('user-42', $issuedAt));
    }

    public function test_revoke_all_for_user_does_not_reject_a_token_issued_after_the_call(): void
    {
        $store = new RevocationStore(new InMemorySimpleCache());

        $store->revokeAllForUser('user-42', 60);

        self::assertFalse($store->isRevokedForUser('user-42', time() + 10));
    }

    public function test_a_token_issued_in_the_exact_same_second_as_the_cutoff_is_revoked(): void
    {
        // The cutoff is inclusive by design (see RevocationStore's own
        // docblock): a same-second tie fails closed, revoked, rather
        // than open. This is the one case that flipped when the cutoff
        // comparison changed from a strict < to <=.
        //
        // Reads the literal cutoff value back out of the cache directly
        // (using RevocationStore's own documented key-naming scheme)
        // instead of sampling time() a second time in this test — two
        // separate real time() calls straddling a second boundary is
        // exactly the flakiness this avoids, even though the odds of it
        // are low.
        $cache = new InMemorySimpleCache();
        $store = new RevocationStore($cache);
        $store->revokeAllForUser('user-42', 60);

        $cutoff = $cache->get('jwt-revoked-user.' . hash('sha256', 'user-42'));
        self::assertIsInt($cutoff);

        self::assertTrue($store->isRevokedForUser('user-42', $cutoff));
    }

    public function test_revoking_all_for_one_user_does_not_affect_another(): void
    {
        $store = new RevocationStore(new InMemorySimpleCache());
        $issuedAt = time() - 10;

        $store->revokeAllForUser('user-42', 60);

        self::assertTrue($store->isRevokedForUser('user-42', $issuedAt));
        self::assertFalse($store->isRevokedForUser('user-99', $issuedAt));
    }

    public function test_revoke_all_for_user_accepts_an_int_user_id(): void
    {
        $store = new RevocationStore(new InMemorySimpleCache());
        $issuedAt = time() - 10;

        $store->revokeAllForUser(42, 60);

        self::assertTrue($store->isRevokedForUser(42, $issuedAt));
    }

    public function test_construction_over_a_null_cache_throws_instead_of_silently_not_revoking(): void
    {
        $this->expectException(RevocationUnavailableException::class);

        new RevocationStore(new NullSimpleCache());
    }
}
