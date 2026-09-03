<?php

declare(strict_types=1);

namespace Kinetis\AuthJwt\Tests;

use Kinetis\AuthJwt\Exception\RefreshTokenUnavailableException;
use Kinetis\AuthJwt\RefreshTokenStore;
use Kinetis\AuthJwt\Tests\Fixtures\FailingSimpleCache;
use Kinetis\AuthJwt\Tests\Fixtures\InMemorySimpleCache;
use Kinetis\AuthJwt\Tests\Fixtures\NonAtomicSimpleCache;
use Kinetis\SimpleCache\NullSimpleCache;
use PHPUnit\Framework\TestCase;

final class RefreshTokenStoreTest extends TestCase
{
    /**
     * A conforming PSR-16 cache may report a failed write by returning
     * false rather than throwing — issue() must not hand back a token
     * that was never actually stored.
     */
    public function test_issue_throws_when_the_cache_write_fails(): void
    {
        $store = new RefreshTokenStore(new FailingSimpleCache());
        $secretSubject = 'super-secret-subject-must-never-leak-into-a-message';

        try {
            $store->issue($secretSubject);
            self::fail('Expected a RefreshTokenUnavailableException.');
        } catch (RefreshTokenUnavailableException $e) {
            self::assertStringNotContainsString($secretSubject, $e->getMessage());
        }
    }

    public function test_revoke_throws_when_the_cache_delete_fails(): void
    {
        $store = new RefreshTokenStore(new FailingSimpleCache());
        $secretToken = 'super-secret-refresh-token-must-never-leak';

        try {
            $store->revoke($secretToken);
            self::fail('Expected a RefreshTokenUnavailableException.');
        } catch (RefreshTokenUnavailableException $e) {
            self::assertStringNotContainsString($secretToken, $e->getMessage());
        }
    }

    public function test_revoke_all_for_user_throws_when_the_cache_write_fails(): void
    {
        $store = new RefreshTokenStore(new FailingSimpleCache());
        $secretUserId = 'super-secret-user-id-must-never-leak-into-a-message';

        try {
            $store->revokeAllForUser($secretUserId, 60);
            self::fail('Expected a RefreshTokenUnavailableException.');
        } catch (RefreshTokenUnavailableException $e) {
            self::assertStringNotContainsString($secretUserId, $e->getMessage());
        }
    }

    public function test_issue_rejects_a_zero_ttl(): void
    {
        $store = new RefreshTokenStore(new InMemorySimpleCache());

        $this->expectException(RefreshTokenUnavailableException::class);

        $store->issue(42, ttlSeconds: 0);
    }

    public function test_issue_rejects_a_negative_ttl(): void
    {
        $store = new RefreshTokenStore(new InMemorySimpleCache());

        $this->expectException(RefreshTokenUnavailableException::class);

        $store->issue(42, ttlSeconds: -60);
    }

    public function test_revoke_all_for_user_rejects_a_zero_ttl(): void
    {
        $store = new RefreshTokenStore(new InMemorySimpleCache());

        $this->expectException(RefreshTokenUnavailableException::class);

        $store->revokeAllForUser(42, 0);
    }

    public function test_revoke_all_for_user_rejects_a_negative_ttl(): void
    {
        $store = new RefreshTokenStore(new InMemorySimpleCache());

        $this->expectException(RefreshTokenUnavailableException::class);

        $store->revokeAllForUser(42, -60);
    }

    public function test_a_freshly_issued_token_redeems_to_its_own_subject_and_claims(): void
    {
        $store = new RefreshTokenStore(new InMemorySimpleCache());

        $token = $store->issue(42, ['role' => 'admin']);
        $result = $store->redeem($token);

        self::assertSame(['subject' => 42, 'claims' => ['role' => 'admin']], $result);
    }

    public function test_redeeming_an_unknown_token_returns_null(): void
    {
        $store = new RefreshTokenStore(new InMemorySimpleCache());

        self::assertNull($store->redeem('not-a-real-token'));
    }

    public function test_a_token_is_single_use_a_second_redeem_returns_null(): void
    {
        $store = new RefreshTokenStore(new InMemorySimpleCache());

        $token = $store->issue(42);
        $store->redeem($token);

        self::assertNull($store->redeem($token));
    }

    public function test_revoke_makes_a_token_unredeemable_without_ever_redeeming_it(): void
    {
        $store = new RefreshTokenStore(new InMemorySimpleCache());

        $token = $store->issue(42);
        $store->revoke($token);

        self::assertNull($store->redeem($token));
    }

    public function test_two_issued_tokens_are_independently_redeemable(): void
    {
        $store = new RefreshTokenStore(new InMemorySimpleCache());

        $tokenA = $store->issue(1);
        $tokenB = $store->issue(2);

        self::assertSame(['subject' => 1, 'claims' => []], $store->redeem($tokenA));
        self::assertSame(['subject' => 2, 'claims' => []], $store->redeem($tokenB));
    }

    public function test_revoke_all_for_user_invalidates_a_token_issued_before_the_call(): void
    {
        $store = new RefreshTokenStore(new InMemorySimpleCache());

        $token = $store->issue(42);
        $store->revokeAllForUser(42, 60);

        self::assertNull($store->redeem($token));
    }

    public function test_revoke_all_for_user_does_not_affect_a_token_issued_after_the_call(): void
    {
        $store = new RefreshTokenStore(new InMemorySimpleCache());

        $store->revokeAllForUser(42, 60);
        // A real second elapsed to guarantee this token's issuedAt is
        // strictly after the cutoff above, not tied to it — avoids
        // depending on two real time() calls landing in the same second.
        sleep(1);
        $token = $store->issue(42);

        self::assertSame(['subject' => 42, 'claims' => []], $store->redeem($token));
    }

    public function test_revoke_all_for_user_does_not_affect_a_different_user(): void
    {
        $store = new RefreshTokenStore(new InMemorySimpleCache());

        $token = $store->issue(42);
        $store->revokeAllForUser(99, 60);

        self::assertSame(['subject' => 42, 'claims' => []], $store->redeem($token));
    }

    public function test_construction_over_a_null_cache_throws_instead_of_silently_issuing_unredeemable_tokens(): void
    {
        try {
            new RefreshTokenStore(new NullSimpleCache());
            self::fail('Expected RefreshTokenUnavailableException to be thrown.');
        } catch (RefreshTokenUnavailableException $e) {
            self::assertSame(
                'RefreshTokenStore requires a real cache: NullSimpleCache never stores anything, so every '
                . 'issued refresh token would be unredeemable and revokeAllForUser() would have nothing to '
                . 'affect. Configure Redis (REDIS_URL/REDIS_HOST) or pass another PSR-16 CacheInterface '
                . 'implementation.',
                $e->getMessage(),
            );
        }
    }

    public function test_construction_over_a_non_atomic_cache_throws_instead_of_silently_allowing_replay(): void
    {
        try {
            new RefreshTokenStore(new NonAtomicSimpleCache());
            self::fail('Expected RefreshTokenUnavailableException to be thrown.');
        } catch (RefreshTokenUnavailableException $e) {
            self::assertSame(
                'RefreshTokenStore requires a cache implementing Kinetis\SimpleCache\AtomicConsumeInterface: '
                . 'redeeming a token by reading it and deleting it in two separate calls lets two concurrent '
                . 'redeems of the same token both succeed, defeating single use. Kinetis\SimpleCache\RedisSimpleCache '
                . 'and ClusteredRedisSimpleCache (kinetis/cache-redis) both implement it.',
                $e->getMessage(),
            );
        }
    }
}
