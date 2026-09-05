<?php

declare(strict_types=1);

namespace Kinetis\AwsSigV4\Tests;

use AsyncAws\Core\Credentials\Credentials;
use Kinetis\AwsSigV4\Exception\NetworkFailureException;
use Kinetis\AwsSigV4\Exception\SigningException;
use Kinetis\AwsSigV4\Exception\TransportFailureException;
use Kinetis\AwsSigV4\Exception\UnsignableRequestException;
use Kinetis\AwsSigV4\Exception\UntrustedOriginException;
use Kinetis\AwsSigV4\SigV4SigningClient;
use Kinetis\AwsSigV4\Tests\Support\FailingTransport;
use Kinetis\AwsSigV4\Tests\Support\FixedCredentialProvider;
use Kinetis\AwsSigV4\Tests\Support\RawUri;
use Kinetis\AwsSigV4\Tests\Support\RecordingTransport;
use Kinetis\AwsSigV4\Tests\Support\SecretChannels;
use Kinetis\AwsSigV4\Tests\Support\ThrowingCredentialProvider;
use Kinetis\AwsSigV4\Tests\Support\ThrowingStream;
use Nyholm\Psr7\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Client\RequestExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

/**
 * Every failure this package raises is checked against sentinel values
 * standing in for the real secrets: the access key, the secret key, the
 * session token, a request header and body, the URL's userinfo and query
 * string, the credential provider's endpoint and token-file text, the
 * underlying failure text, and the signed `Authorization` value. None of
 * them may reach any channel {@see SecretChannels} renders.
 *
 * Each sentinel is a word, not a value in the shape of the thing it
 * stands in for: a secret scanner reads a test fixture and a leaked
 * credential the same way, and a string shaped like an AWS access key id
 * would have to be excused from that scan by name. What is asserted is
 * whether a value comes back out, never how it is spelled.
 */
final class FailureSecrecyTest extends TestCase
{
    private const string ORIGIN = 'https://api.example.com';

    private const string ACCESS_KEY = 'ACCESSKEYIDSENTINEL';

    private const string SECRET_KEY = 'SECRETACCESSKEYSENTINEL';

    private const string SESSION_TOKEN = 'SESSIONTOKENSENTINEL';

    private const string HEADER_VALUE = 'REQUESTHEADERSENTINEL';

    private const string BODY = 'REQUESTBODYSENTINEL';

    private const string USERINFO = 'USERSENTINEL:PASSWORDSENTINEL';

    private const string QUERY_VALUE = 'QUERYSTRINGSENTINEL';

    /**
     * @return list<string>
     */
    private static function sentinels(): array
    {
        return [
            self::ACCESS_KEY,
            self::SECRET_KEY,
            self::SESSION_TOKEN,
            self::HEADER_VALUE,
            self::BODY,
            'USERSENTINEL',
            'PASSWORDSENTINEL',
            self::QUERY_VALUE,
            'PROVIDER-ENDPOINT-SENTINEL',
            'TOKEN-FILE-SENTINEL',
            ThrowingStream::FAILURE_MESSAGE,
            FailingTransport::FAILURE_MESSAGE,
            'AWS4-HMAC-SHA256',
        ];
    }

    public function test_an_untrusted_origin_rejection_carries_nothing(): void
    {
        $this->assertFailureIsSilent(
            UntrustedOriginException::class,
            $this->client(FixedCredentialProvider::example(), new RecordingTransport()->asTransport()),
            self::offOriginRequest(),
        );
    }

    public function test_a_credential_provider_failure_carries_nothing(): void
    {
        $this->assertFailureIsSilent(
            UnsignableRequestException::class,
            $this->client(new ThrowingCredentialProvider(), new RecordingTransport()->asTransport()),
            self::onOriginRequest(),
        );
    }

    public function test_unresolvable_credentials_carry_nothing(): void
    {
        $this->assertFailureIsSilent(
            UnsignableRequestException::class,
            $this->client(FixedCredentialProvider::none(), new RecordingTransport()->asTransport()),
            self::onOriginRequest(),
        );
    }

    public function test_a_body_capture_failure_carries_nothing(): void
    {
        $this->assertFailureIsSilent(
            UnsignableRequestException::class,
            $this->client(FixedCredentialProvider::example(), new RecordingTransport()->asTransport()),
            self::onOriginRequest()->withBody(new ThrowingStream()),
        );
    }

    /**
     * The signing step's own conversion, which no target can reach: the
     * URI handed to the signer is built by this package and verified
     * against what the request renders before anything is signed, so
     * only `SignerV4` failing on its own could raise this. The fixed
     * message and the safe request still have to hold when it does.
     */
    public function test_a_signing_failure_carries_nothing(): void
    {
        $request = self::onOriginRequest();
        $failure = UnsignableRequestException::signingFailed($request);

        self::assertSame(UnsignableRequestException::SIGNING_FAILED, $failure->getMessage());
        self::assertSame($request, $failure->getRequest());
        self::assertNull($failure->getPrevious());
        $this->assertNoSentinelIn($failure, self::sentinels());
    }

    /**
     * @return iterable<string, array{transport: HttpClientInterface, expected: class-string<Throwable>}>
     */
    public static function transportFailureProvider(): iterable
    {
        yield 'connectivity' => [
            'transport' => FailingTransport::connectivity(),
            'expected' => NetworkFailureException::class,
        ];
        yield 'timeout' => [
            'transport' => FailingTransport::timeout(),
            'expected' => NetworkFailureException::class,
        ];
        yield 'rejected request' => [
            'transport' => FailingTransport::rejectedRequest(),
            'expected' => TransportFailureException::class,
        ];
    }

    /**
     * The transport failure reaches this package through Symfony's
     * PSR-18 adapter, whose own exception holds the signed request and
     * repeats every header it was handed. Whichever of the two PSR-18
     * categories it lands in, none of that survives the conversion.
     *
     * @param class-string<Throwable> $expected
     */
    #[DataProvider('transportFailureProvider')]
    public function test_a_transport_failure_carries_nothing(
        HttpClientInterface $transport,
        string $expected,
    ): void {
        $this->assertFailureIsSilent(
            $expected,
            $this->client(
                new FixedCredentialProvider(
                    new Credentials(self::ACCESS_KEY, self::SECRET_KEY, self::SESSION_TOKEN),
                ),
                $transport,
            ),
            self::onOriginRequest(),
        );
    }

    /**
     * PSR-18 requires a caller to be able to tell a connection that
     * never answered from a request the transport would not accept:
     * retrying is meaningful for the first and pointless for the second.
     * Converting every transport failure into one type would erase that,
     * so the classification the owned PSR-18 boundary makes is the one
     * carried across.
     *
     * @param class-string<Throwable> $expected
     */
    #[DataProvider('transportFailureProvider')]
    public function test_a_transport_failure_keeps_its_psr18_classification(
        HttpClientInterface $transport,
        string $expected,
    ): void {
        try {
            $this->client(FixedCredentialProvider::example(), $transport)
                ->sendRequest(self::onOriginRequest());

            self::fail("Expected a {$expected} to be thrown.");
        } catch (Throwable $e) {
            self::assertInstanceOf($expected, $e);

            $isNetwork = $expected === NetworkFailureException::class;

            self::assertSame($isNetwork, $e instanceof NetworkExceptionInterface);
            self::assertSame(!$isNetwork, $e instanceof RequestExceptionInterface);
        }
    }

    /**
     * @return iterable<string, list<string>>
     */
    public static function secretBearingOriginProvider(): iterable
    {
        yield 'userinfo password' => ['https://USERSENTINEL:PASSWORDSENTINEL@api.example.com'];
        yield 'query string token' => ['https://api.example.com?token=QUERYSTRINGSENTINEL'];
        yield 'fragment' => ['https://api.example.com#QUERYSTRINGSENTINEL'];
        yield 'unsupported scheme' => ['ftp://USERSENTINEL:PASSWORDSENTINEL@api.example.com'];
        yield 'unparseable' => ['ht!tp://USERSENTINEL:PASSWORDSENTINEL@api.example.com'];
        yield 'invalid port' => ['https://api.example.com:PASSWORDSENTINEL'];
    }

    /**
     * A configured origin can itself carry a credential, and every way
     * one is rejected has to keep it out of the exception.
     */
    #[DataProvider('secretBearingOriginProvider')]
    public function test_a_rejected_origin_never_appears_in_the_exception(string $origin): void
    {
        try {
            new SigV4SigningClient(
                $origin,
                'us-east-1',
                'es',
                FixedCredentialProvider::example(),
                null,
                new RecordingTransport()->asTransport(),
            );

            self::fail('Expected a SigningException to be thrown.');
        } catch (SigningException $e) {
            $this->assertNoSentinelIn($e, [$origin, ...self::sentinels()]);
        }
    }

    /**
     * What survives serialization is the message and a request stripped
     * to its method, scheme, host, port and path — no headers, no body,
     * no userinfo, no query string — so an exception queued or cached by
     * a caller still names which endpoint failed and carries nothing
     * else.
     */
    public function test_a_serialized_failure_round_trips_without_its_secrets(): void
    {
        try {
            $this->client(FixedCredentialProvider::none(), new RecordingTransport()->asTransport())
                ->sendRequest(self::onOriginRequest());

            self::fail('Expected an UnsignableRequestException to be thrown.');
        } catch (UnsignableRequestException $e) {
            $restored = unserialize(serialize($e));

            self::assertInstanceOf(UnsignableRequestException::class, $restored);
            self::assertSame(UnsignableRequestException::CREDENTIALS_UNAVAILABLE, $restored->getMessage());
            self::assertSame('POST', $restored->getRequest()->getMethod());
            self::assertSame('https://api.example.com/users', (string) $restored->getRequest()->getUri());
            self::assertSame([], $restored->getRequest()->getHeaders());
            self::assertSame('', (string) $restored->getRequest()->getBody());
        }
    }

    public function test_a_serialized_configuration_failure_round_trips(): void
    {
        $restored = unserialize(serialize(SigningException::originHasInvalidPort()));

        self::assertInstanceOf(SigningException::class, $restored);
        self::assertSame(SigningException::ORIGIN_INVALID_PORT, $restored->getMessage());
        self::assertNull($restored->getPrevious());
    }

    /**
     * @param class-string<Throwable> $expected
     */
    private function assertFailureIsSilent(
        string $expected,
        SigV4SigningClient $client,
        RequestInterface $request,
    ): void {
        try {
            $client->sendRequest($request);

            self::fail("Expected a {$expected} to be thrown.");
        } catch (Throwable $e) {
            self::assertInstanceOf($expected, $e);
            self::assertInstanceOf(ClientExceptionInterface::class, $e);
            self::assertNull($e->getPrevious());
            self::assertSame($request, $e->getRequest());

            $this->assertNoSentinelIn($e, self::sentinels());
        }
    }

    /**
     * @param list<string> $sentinels
     */
    private function assertNoSentinelIn(Throwable $exception, array $sentinels): void
    {
        foreach (SecretChannels::of($exception) as $channel => $rendered) {
            foreach ($sentinels as $sentinel) {
                self::assertStringNotContainsString(
                    $sentinel,
                    $rendered,
                    "{$sentinel} reached the {$channel} of " . $exception::class,
                );
            }
        }
    }

    private function client(
        \AsyncAws\Core\Credentials\CredentialProvider $credentialProvider,
        HttpClientInterface $transport,
    ): SigV4SigningClient {
        return new SigV4SigningClient(self::ORIGIN, 'us-east-1', 'es', $credentialProvider, null, $transport);
    }

    private static function onOriginRequest(): Request
    {
        return self::requestFor('https://api.example.com/users?token=' . self::QUERY_VALUE);
    }

    /**
     * Userinfo and a foreign host, both of which the origin check
     * rejects, alongside the same header, body, and query sentinels.
     */
    private static function offOriginRequest(): Request
    {
        return self::requestFor(
            'https://' . self::USERINFO . '@evil.example.com/users?token=' . self::QUERY_VALUE,
        );
    }

    private static function requestFor(string|RawUri $uri): Request
    {
        return new Request('POST', $uri, ['X-Secret' => self::HEADER_VALUE], self::BODY);
    }
}
