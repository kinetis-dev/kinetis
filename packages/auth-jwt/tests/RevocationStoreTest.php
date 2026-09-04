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

    public function test_revoke_all_for_user_throws_when_the_cache_write_fails(): void
    {
        $store = new RevocationStore(new FailingSimpleCache());
        $secretUserId = 'super-secret-user-id-must-never-leak-into-a-message';

        try {
            $store->revokeAllForUser($secretUserId, 60);
            self::fail('Expected a RevocationUnavailableException.');
        } catch (RevocationUnavailableException $e) {
            self::assertStringNotContainsString($secretUserId, $e->getMessage());
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

    public function test_revoke_all_for_user_rejects_a_zero_ttl(): void
    {
        $store = new RevocationStore(new InMemorySimpleCache());

        $this->expectException(RevocationUnavailableException::class);

        $store->revokeAllForUser('user-42', 0);
    }

    public function test_revoke_all_for_user_rejects_a_negative_ttl(): void
    {
        $store = new RevocationStore(new InMemorySimpleCache());

        $this->expectException(RevocationUnavailableException::class);

        $store->revokeAllForUser('user-42', -60);
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

        $cutoff = $cache->get('jwt-revoked-user.' . hash('sha256', 's:user-42'));
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

    /**
     * revokeAllForUser() and isRevokedForUser() both accept string|int,
     * and the type is part of the identity: an app keying users by
     * integer id and one keying them by string id are both served here,
     * but 42 and '42' are not the same user in either. Sharing one key
     * between them would make "log out everywhere" for one identity log
     * out an unrelated other.
     */
    public function test_an_int_user_id_and_its_numeric_string_form_are_separate_identities(): void
    {
        $store = new RevocationStore(new InMemorySimpleCache());
        $issuedAt = time() - 10;

        $store->revokeAllForUser(42, 60);

        self::assertTrue($store->isRevokedForUser(42, $issuedAt));
        self::assertFalse($store->isRevokedForUser('42', $issuedAt));
    }

    public function test_a_numeric_string_user_id_and_its_int_form_are_separate_identities(): void
    {
        $store = new RevocationStore(new InMemorySimpleCache());
        $issuedAt = time() - 10;

        $store->revokeAllForUser('42', 60);

        self::assertTrue($store->isRevokedForUser('42', $issuedAt));
        self::assertFalse($store->isRevokedForUser(42, $issuedAt));
    }

    /**
     * The type tag a subject id is framed with before hashing is part of
     * the hashed material, so a string carrying that tag's own shape is
     * still just a string — it can't be framed into the integer's key.
     */
    public function test_a_string_user_id_shaped_like_an_int_framing_stays_separate(): void
    {
        $store = new RevocationStore(new InMemorySimpleCache());
        $issuedAt = time() - 10;

        $store->revokeAllForUser(42, 60);

        self::assertFalse($store->isRevokedForUser('i:42', $issuedAt));
    }

    /**
     * An empty string is a valid id as far as this store's own contract
     * goes — string|int with no emptiness check, unlike the non-empty
     * `jti` revokeToken() insists on — so it gets a key of its own like
     * any other value rather than collapsing onto 0 or ''-adjacent ids.
     */
    public function test_an_empty_string_user_id_is_its_own_identity(): void
    {
        $store = new RevocationStore(new InMemorySimpleCache());
        $issuedAt = time() - 10;

        $store->revokeAllForUser('', 60);

        self::assertTrue($store->isRevokedForUser('', $issuedAt));
        self::assertFalse($store->isRevokedForUser(0, $issuedAt));
        self::assertFalse($store->isRevokedForUser('0', $issuedAt));
    }

    public function test_construction_over_a_null_cache_throws_instead_of_silently_not_revoking(): void
    {
        $this->expectException(RevocationUnavailableException::class);

        new RevocationStore(new NullSimpleCache());
    }
}
