<?php

declare(strict_types=1);

namespace Kinetis\AuthJwt\Tests;

use DomainException;
use Kinetis\AuthJwt\Exception\JwtConfigurationException;
use Kinetis\AuthJwt\Exception\RefreshTokenUnavailableException;
use Kinetis\AuthJwt\Exception\RevocationUnavailableException;
use Kinetis\AuthJwt\JwtAuthMiddleware;
use Kinetis\AuthJwt\JwtIssuer;
use Kinetis\AuthJwt\JwtSigningKey;
use Kinetis\AuthJwt\JwtVerificationKeys;
use Kinetis\AuthJwt\RefreshTokenStore;
use Kinetis\AuthJwt\RevocationStore;
use Kinetis\AuthJwt\Tests\Fixtures\FailingSimpleCache;
use Kinetis\Container\AppScope;
use Kinetis\Http\CallableRequestHandler;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SensitiveParameterValue;
use Throwable;

/**
 * A stack frame carries the arguments it was called with, so any
 * backtrace renders them. `#[\SensitiveParameter]` puts a
 * SensitiveParameterValue in the frame instead.
 *
 * One representative failure per credential this package passes — key
 * material, issued claims, the request a bearer token arrived in, a
 * refresh token, a revocation id — against the public entry path that
 * carries it. Frames owned by firebase/php-jwt, PSR-7 and the
 * application are outside this package's reach and are not asserted on.
 */
final class TraceSecrecyTest extends TestCase
{
    private const string SECRET = 'trace-secrecy-hmac-secret-that-must-never-leak-and-is-long-enough';

    private const string CLAIM = 'trace-secrecy-claim-must-never-leak';

    private const string REFRESH_TOKEN = 'trace-secrecy-refresh-token-must-never-leak';

    private const string JTI = 'trace-secrecy-jti-must-never-leak';

    private string $ignoreArguments = '0';

    #[\Override]
    protected function setUp(): void
    {
        // A trace carries arguments only while this is off, and that is
        // the case the redaction exists for.
        $this->ignoreArguments = (string) ini_get('zend.exception_ignore_args');
        ini_set('zend.exception_ignore_args', '0');
    }

    #[\Override]
    protected function tearDown(): void
    {
        ini_set('zend.exception_ignore_args', $this->ignoreArguments);
    }

    public function test_a_rejected_key_hides_its_material(): void
    {
        try {
            JwtSigningKey::hmacSecret(self::SECRET, 'RS256');
            self::fail('Expected an unsupported-algorithm rejection.');
        } catch (JwtConfigurationException $e) {
            self::assertRedactedAt($e, JwtSigningKey::class . '::hmacSecret', self::SECRET);
        }
    }

    public function test_a_signing_failure_hides_the_issued_claims(): void
    {
        $issuer = new JwtIssuer(JwtSigningKey::hmacSecret(self::SECRET));

        try {
            // Invalid UTF-8 has no JSON encoding, so JWT::encode() fails
            // below both of this package's own signing frames.
            $issuer->issue('user-42', ['role' => self::CLAIM, 'unencodable' => "\xB1\x31"]);
            self::fail('Expected the payload to fail JSON encoding.');
        } catch (DomainException $e) {
            self::assertRedactedAt($e, JwtIssuer::class . '::issue', self::CLAIM);
            self::assertRedactedAt($e, JwtSigningKey::class . '::sign', self::CLAIM);
        }
    }

    public function test_a_failure_under_the_middleware_hides_the_bearer_request(): void
    {
        $token = new JwtIssuer(JwtSigningKey::hmacSecret(self::SECRET))->issue('user-42');

        $app = new AppScope();
        $app->boot();

        $middleware = new JwtAuthMiddleware(JwtVerificationKeys::hmacSecret(self::SECRET), $app->createRequestScope());

        try {
            $middleware->process(
                new ServerRequest('GET', '/', headers: ['Authorization' => "Bearer {$token}"]),
                new CallableRequestHandler(static fn (): never => throw new RuntimeException('handler failure')),
            );
            self::fail('Expected the handler failure to propagate.');
        } catch (RuntimeException $e) {
            self::assertRedactedAt($e, JwtAuthMiddleware::class . '::process', $token);
        }
    }

    public function test_a_failing_refresh_store_hides_the_refresh_token(): void
    {
        try {
            new RefreshTokenStore(new FailingSimpleCache())->revoke(self::REFRESH_TOKEN);
            self::fail('Expected a RefreshTokenUnavailableException.');
        } catch (RefreshTokenUnavailableException $e) {
            self::assertRedactedAt($e, RefreshTokenStore::class . '::revoke', self::REFRESH_TOKEN);
        }
    }

    public function test_a_failing_revocation_store_hides_the_revocation_id(): void
    {
        try {
            new RevocationStore(new FailingSimpleCache())->revoke(self::JTI, 60);
            self::fail('Expected a RevocationUnavailableException.');
        } catch (RevocationUnavailableException $e) {
            self::assertRedactedAt($e, RevocationStore::class . '::revoke', self::JTI);
        }
    }

    /**
     * Asserts that $frame is on the stack with an argument replaced, and
     * that $secret appears in none of this package's own frames.
     */
    private static function assertRedactedAt(Throwable $failure, string $frame, string $secret): void
    {
        $owned = [];
        $redacted = [];

        foreach ($failure->getTrace() as $call) {
            $class = is_string($call['class'] ?? null) ? $call['class'] : '';

            if (!str_starts_with($class, 'Kinetis\\AuthJwt\\') || str_starts_with($class, 'Kinetis\\AuthJwt\\Tests\\')) {
                continue;
            }

            $arguments = (array) ($call['args'] ?? []);
            $owned[] = $arguments;

            if ($class . '::' . $call['function'] === $frame) {
                $redacted = [...$redacted, ...array_filter(
                    $arguments,
                    static fn (mixed $argument): bool => $argument instanceof SensitiveParameterValue,
                )];
            }
        }

        self::assertNotEmpty($redacted, "{$frame} carries no redacted argument.");
        self::assertStringNotContainsString(
            $secret,
            print_r($owned, true),
            'A credential survived in this package\'s own frames.',
        );
    }
}
