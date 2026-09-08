<?php

declare(strict_types=1);

namespace Kinetis\AuthJwt\Tests;

use Kinetis\AuthJwt\Exception\RefreshTokenUnavailableException;
use Kinetis\AuthJwt\RefreshTokenStore;
use Kinetis\AuthJwt\Tests\Fixtures\FailingSimpleCache;
use Kinetis\AuthJwt\Tests\Fixtures\InMemorySimpleCache;
use Kinetis\AuthJwt\Tests\Fixtures\NonAtomicSimpleCache;
use Kinetis\SimpleCache\NullSimpleCache;
use PHPUnit\Framework\Attributes\DataProvider;
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

    public function test_issue_rejects_a_zero_ttl(): void
    {
        $store = new RefreshTokenStore(new InMemorySimpleCache());

        $this->expectException(RefreshTokenUnavailableException::class);

        $store->issue('42', ttlSeconds: 0);
    }

    public function test_issue_rejects_a_negative_ttl(): void
    {
        $store = new RefreshTokenStore(new InMemorySimpleCache());

        $this->expectException(RefreshTokenUnavailableException::class);

        $store->issue('42', ttlSeconds: -60);
    }

    public function test_a_freshly_issued_token_redeems_to_its_own_subject_and_claims(): void
    {
        $store = new RefreshTokenStore(new InMemorySimpleCache());

        $token = $store->issue(42, ['role' => 'admin']);
        $result = $store->redeem($token);

        // An integer application id is canonicalized once, at issue()
        // — the same conversion JwtIssuer::issue() makes for `sub`.
        self::assertSame(['subject' => '42', 'claims' => ['role' => 'admin']], $result);
    }

    public function test_redeeming_an_unknown_token_returns_null(): void
    {
        $store = new RefreshTokenStore(new InMemorySimpleCache());

        self::assertNull($store->redeem('not-a-real-token'));
    }

    public function test_a_token_is_single_use_a_second_redeem_returns_null(): void
    {
        $store = new RefreshTokenStore(new InMemorySimpleCache());

        $token = $store->issue('42');
        $store->redeem($token);

        self::assertNull($store->redeem($token));
    }

    public function test_revoke_makes_a_token_unredeemable_without_ever_redeeming_it(): void
    {
        $store = new RefreshTokenStore(new InMemorySimpleCache());

        $token = $store->issue('42');
        $store->revoke($token);

        self::assertNull($store->redeem($token));
    }

    public function test_two_issued_tokens_are_independently_redeemable(): void
    {
        $store = new RefreshTokenStore(new InMemorySimpleCache());

        $tokenA = $store->issue('1');
        $tokenB = $store->issue('2');

        self::assertSame(['subject' => '1', 'claims' => []], $store->redeem($tokenA));
        self::assertSame(['subject' => '2', 'claims' => []], $store->redeem($tokenB));
    }

    /**
     * An integer application id and the string an access token carries
     * name one subject, so a refresh token issued from either redeems
     * back to the canonical string form — the property that lets a
     * refresh endpoint reissue under JwtUser::id().
     */
    public function test_a_token_issued_from_an_int_id_redeems_to_its_canonical_string(): void
    {
        $store = new RefreshTokenStore(new InMemorySimpleCache());

        $token = $store->issue(42);

        self::assertSame(['subject' => '42', 'claims' => []], $store->redeem($token));
    }

    public function test_issue_rejects_an_empty_subject(): void
    {
        $store = new RefreshTokenStore(new InMemorySimpleCache());

        $this->expectException(RefreshTokenUnavailableException::class);

        $store->issue('');
    }

    /**
     * A record whose subject is not the canonical non-empty string
     * issue() stores could only come from a tampered or foreign cache
     * entry — it fails closed rather than being reinterpreted into an
     * identity revocation would then miss.
     */
    #[DataProvider('nonCanonicalStoredSubjects')]
    public function test_a_stored_record_with_a_non_canonical_subject_is_unredeemable(mixed $subject): void
    {
        $cache = new InMemorySimpleCache();
        $store = new RefreshTokenStore($cache);

        $token = 'planted-refresh-token';
        $cache->set('jwt-refresh.' . hash('sha256', $token), [
            'subject' => $subject,
            'claims' => [],
        ], 60);

        self::assertNull($store->redeem($token));
    }

    public static function nonCanonicalStoredSubjects(): iterable
    {
        yield 'an integer' => [42];
        yield 'an empty string' => [''];
        yield 'a float' => [42.5];
        yield 'a boolean' => [true];
        yield 'a list' => [['42']];
    }

    public function test_construction_over_a_null_cache_throws_instead_of_silently_issuing_unredeemable_tokens(): void
    {
        try {
            new RefreshTokenStore(new NullSimpleCache());
            self::fail('Expected RefreshTokenUnavailableException to be thrown.');
        } catch (RefreshTokenUnavailableException $e) {
            self::assertSame(
                'RefreshTokenStore requires a real cache: NullSimpleCache never stores anything, so every '
                . 'issued refresh token would be unredeemable. Configure Redis (REDIS_URL/REDIS_HOST) or pass '
                . 'another PSR-16 CacheInterface implementation.',
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
                . 'redeems of the same token both succeed, defeating single use. Install kinetis/cache-redis '
                . 'for a Redis-backed cache that implements it.',
                $e->getMessage(),
            );
        }
    }
}
