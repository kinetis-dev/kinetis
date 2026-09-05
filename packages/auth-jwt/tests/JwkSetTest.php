<?php

declare(strict_types=1);

namespace Kinetis\AuthJwt\Tests;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Kinetis\AuthJwt\Exception\JwkSetException;
use Kinetis\AuthJwt\JwkSet;
use Kinetis\AuthJwt\JwtAuthMiddleware;
use Kinetis\AuthJwt\JwtIssuer;
use Kinetis\AuthJwt\JwtKeyValidator;
use Kinetis\AuthJwt\ParsedJwkSet;
use Kinetis\AuthJwt\PublishedRsaKey;
use Kinetis\AuthJwt\Tests\Fixtures\RsaKeyPair;
use Kinetis\AuthJwt\Tests\Fixtures\SecondRsaKeyPair;
use Kinetis\AuthJwt\Tests\Fixtures\UndersizedRsaKeyPair;
use Kinetis\Container\AppScope;
use Kinetis\Http\CallableRequestHandler;
use Kinetis\Http\CurrentUserInterface;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class JwkSetTest extends TestCase
{
    public function test_builds_a_keys_array_with_one_entry_per_given_kid(): void
    {
        $set = JwkSet::fromRsaPublicKeys([new PublishedRsaKey('key-2026', RsaKeyPair::PUBLIC_KEY)]);

        self::assertCount(1, $set['keys']);
        self::assertSame('key-2026', $set['keys'][0]['kid']);
        self::assertSame('RSA', $set['keys'][0]['kty']);
        self::assertSame('sig', $set['keys'][0]['use']);
        self::assertSame('RS256', $set['keys'][0]['alg']);
    }

    public function test_respects_a_given_algorithm(): void
    {
        $set = JwkSet::fromRsaPublicKeys(
            [new PublishedRsaKey('key-2026', RsaKeyPair::PUBLIC_KEY)],
            algorithm: 'RS384',
        );

        self::assertSame('RS384', $set['keys'][0]['alg']);
    }

    public function test_multiple_keys_produce_multiple_entries_in_order(): void
    {
        $set = JwkSet::fromRsaPublicKeys([
            new PublishedRsaKey('old', RsaKeyPair::PUBLIC_KEY),
            new PublishedRsaKey('new', SecondRsaKeyPair::publicKey()),
        ]);

        self::assertSame(['old', 'new'], array_column($set['keys'], 'kid'));
    }

    /**
     * The three kids a `kid => PEM` map could not have published
     * together — see ParsedJwkSet.
     */
    public function test_publishes_a_decimal_kid_alongside_its_lookalikes(): void
    {
        $set = JwkSet::fromRsaPublicKeys([
            new PublishedRsaKey('0', RsaKeyPair::PUBLIC_KEY),
            new PublishedRsaKey('00', SecondRsaKeyPair::publicKey()),
            new PublishedRsaKey('zero', RsaKeyPair::PUBLIC_KEY),
        ]);

        self::assertSame(['0', '00', 'zero'], array_column($set['keys'], 'kid'));
    }

    public function test_an_invalid_pem_throws_a_named_exception(): void
    {
        $this->expectException(JwkSetException::class);

        JwkSet::fromRsaPublicKeys([new PublishedRsaKey('key-2026', 'not a real pem key')]);
    }

    public function test_the_produced_jwk_is_a_real_usable_key_a_token_verifies_against(): void
    {
        // A client fetching this JWK set and feeding it to a standard
        // JWK-consuming library (firebase/php-jwt's own
        // JWK::parseKeySet(), used here) gets back a key that verifies
        // a token signed with the matching private key.
        $set = JwkSet::fromRsaPublicKeys([new PublishedRsaKey('current', RsaKeyPair::PUBLIC_KEY)]);
        $keys = JWK::parseKeySet($set);

        $token = JWT::encode(['sub' => 'user-42', 'iat' => time()], RsaKeyPair::PRIVATE_KEY, 'RS256', 'current');
        $claims = JWT::decode($token, $keys);

        self::assertSame('user-42', $claims->sub);
    }

    /**
     * The same round trip one step further: the parsed key set feeds a
     * real JwtAuthMiddleware construction, and a real request carrying
     * a token signed under the matching kid authenticates through it.
     */
    public function test_the_produced_jwk_set_composes_with_a_real_jwt_auth_middleware(): void
    {
        $set = JwkSet::fromRsaPublicKeys([new PublishedRsaKey('current', RsaKeyPair::PUBLIC_KEY)]);
        $keys = JWK::parseKeySet($set);

        $app = new AppScope();
        $app->boot();
        $scope = $app->createRequestScope();
        $middleware = new JwtAuthMiddleware($keys, $scope);

        $token = JWT::encode(['sub' => 'user-42', 'iat' => time()], RsaKeyPair::PRIVATE_KEY, 'RS256', 'current');
        $request = new ServerRequest('GET', '/', headers: ['Authorization' => "Bearer {$token}"]);
        $handler = new CallableRequestHandler(static fn () => new Response(200));

        $response = $middleware->process($request, $handler);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('user-42', $scope->get(CurrentUserInterface::class)->id());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unusableKids(): array
    {
        return [
            'empty' => [''],
            'blank' => ["  \t"],
            'past the length limit' => [str_repeat('k', JwtKeyValidator::MAXIMUM_KID_LENGTH + 1)],
            'not valid UTF-8' => ["key-\xFF"],
        ];
    }

    #[DataProvider('unusableKids')]
    public function test_a_key_cannot_be_published_under_a_kid_no_verifier_would_select(string $kid): void
    {
        $this->expectException(JwkSetException::class);
        $this->expectExceptionMessage('non-blank, valid UTF-8');

        new PublishedRsaKey($kid, RsaKeyPair::PUBLIC_KEY);
    }

    /**
     * The rejection names the rule without carrying the offending bytes
     * into a message.
     */
    public function test_a_kid_outside_utf8_is_refused_without_echoing_its_bytes(): void
    {
        $kid = "key-\xFF";

        try {
            new PublishedRsaKey($kid, RsaKeyPair::PUBLIC_KEY);
            self::fail('Expected a JwkSetException.');
        } catch (JwkSetException $exception) {
            self::assertStringContainsString('not valid UTF-8', $exception->getMessage());
            self::assertStringNotContainsString("\xFF", $exception->getMessage());
            self::assertSame(1, preg_match('//u', $exception->getMessage()));
        }
    }

    /**
     * The publisher, the parser and a token's own header agree on which
     * kids are usable, so a non-ASCII one survives the whole path.
     */
    public function test_a_non_ascii_kid_publishes_and_parses_back(): void
    {
        $kid = 'clé-2026';
        $set = JwkSet::fromRsaPublicKeys([new PublishedRsaKey($kid, RsaKeyPair::PUBLIC_KEY)]);
        $parsed = ParsedJwkSet::fromJson((string) json_encode($set, JSON_THROW_ON_ERROR));

        self::assertSame([$kid], $parsed->kids());

        $app = new AppScope();
        $app->boot();
        $scope = $app->createRequestScope();
        $token = new JwtIssuer(RsaKeyPair::PRIVATE_KEY, algorithm: 'RS256', kid: $kid)->issue('user-42');
        $response = new JwtAuthMiddleware($parsed, $scope)->process(
            new ServerRequest('GET', '/', headers: ['Authorization' => "Bearer {$token}"]),
            new CallableRequestHandler(static fn () => new Response(200)),
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('user-42', $scope->get(CurrentUserInterface::class)->id());
    }

    public function test_a_kid_at_the_length_limit_is_publishable(): void
    {
        $kid = str_repeat('k', JwtKeyValidator::MAXIMUM_KID_LENGTH);

        $set = JwkSet::fromRsaPublicKeys([new PublishedRsaKey($kid, RsaKeyPair::PUBLIC_KEY)]);

        self::assertSame($kid, $set['keys'][0]['kid']);
    }

    public function test_construction_throws_for_an_empty_key_set(): void
    {
        $this->expectException(JwkSetException::class);
        $this->expectExceptionMessage('at least one PublishedRsaKey');

        JwkSet::fromRsaPublicKeys([]);
    }

    public function test_construction_throws_for_a_key_list_with_gaps(): void
    {
        $this->expectException(JwkSetException::class);
        $this->expectExceptionMessage('must be a list');

        JwkSet::fromRsaPublicKeys([3 => new PublishedRsaKey('key-2026', RsaKeyPair::PUBLIC_KEY)]);
    }

    public function test_construction_throws_for_an_entry_that_is_not_a_published_key(): void
    {
        $this->expectException(JwkSetException::class);
        $this->expectExceptionMessage('accepts only PublishedRsaKey values');

        JwkSet::fromRsaPublicKeys([RsaKeyPair::PUBLIC_KEY]);
    }

    public function test_construction_throws_for_a_kid_keyed_map(): void
    {
        $this->expectException(JwkSetException::class);
        $this->expectExceptionMessage('must be a list');

        JwkSet::fromRsaPublicKeys(['key-2026' => RsaKeyPair::PUBLIC_KEY]);
    }

    public function test_construction_throws_when_two_keys_claim_one_kid(): void
    {
        $this->expectException(JwkSetException::class);
        $this->expectExceptionMessage('more than one key under the kid "shared"');

        JwkSet::fromRsaPublicKeys([
            new PublishedRsaKey('shared', RsaKeyPair::PUBLIC_KEY),
            new PublishedRsaKey('shared', SecondRsaKeyPair::publicKey()),
        ]);
    }

    public function test_construction_throws_for_an_unsupported_algorithm(): void
    {
        $this->expectException(JwkSetException::class);

        JwkSet::fromRsaPublicKeys([new PublishedRsaKey('key-2026', RsaKeyPair::PUBLIC_KEY)], algorithm: 'HS256');
    }

    public function test_construction_throws_for_a_nonsense_algorithm(): void
    {
        $this->expectException(JwkSetException::class);

        JwkSet::fromRsaPublicKeys(
            [new PublishedRsaKey('key-2026', RsaKeyPair::PUBLIC_KEY)],
            algorithm: 'not-an-algorithm',
        );
    }

    public function test_construction_throws_for_an_undersized_rsa_key(): void
    {
        $this->expectException(JwkSetException::class);

        JwkSet::fromRsaPublicKeys([new PublishedRsaKey('key-2026', UndersizedRsaKeyPair::PUBLIC_KEY)]);
    }
}
