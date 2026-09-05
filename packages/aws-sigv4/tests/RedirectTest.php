<?php

declare(strict_types=1);

namespace Kinetis\AwsSigV4\Tests;

use Amp\Http\Client\InterceptedHttpClient;
use Amp\Http\Client\PooledHttpClient;
use Kinetis\AwsSigV4\Exception\UnsignableRequestException;
use Kinetis\AwsSigV4\SignedTransport;
use Kinetis\AwsSigV4\SigV4SigningClient;
use Kinetis\AwsSigV4\Tests\Support\FixedCredentialProvider;
use Kinetis\AwsSigV4\Tests\Support\RecordingTransport;
use Nyholm\Psr7\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\AmpHttpClient;
use Symfony\Component\HttpClient\Internal\AmpClientState;
use Symfony\Component\HttpClient\Retry\GenericRetryStrategy;
use Symfony\Component\HttpClient\RetryableHttpClient;

/**
 * A 3xx is the response, not an instruction, and one send is one network
 * attempt. Nothing is re-signed and no second request is made, so a
 * `Location` cannot carry credentials anywhere — neither for a signed
 * request nor for the credential chain's own metadata lookups.
 *
 * Both halves of that are properties of {@see SignedTransport}, which
 * this package constructs and a caller cannot replace: the tests below
 * cover the behavior at the PSR-18 boundary, the AMPHP client the
 * default transport is built over, and the shape that keeps a retrying
 * or redirect-following client from getting underneath a signature.
 */
final class RedirectTest extends TestCase
{
    private const string ORIGIN = 'https://api.example.com';

    /**
     * A word rather than something in the shape of an AWS access key id
     * — see {@see FailureSecrecyTest}. The signature covers it whatever
     * it spells.
     */
    private const string ACCESS_KEY = 'ACCESSKEYREDIRECTSENTINEL';

    private const string SESSION_TOKEN = 'SESSIONTOKENREDIRECTSENTINEL';

    /**
     * @var array<string, string|null>
     */
    private array $originalEnv = [];

    #[\Override]
    protected function tearDown(): void
    {
        foreach ($this->originalEnv as $name => $value) {
            if ($value === null) {
                unset($_ENV[$name]);
            } else {
                $_ENV[$name] = $value;
            }
        }

        $this->originalEnv = [];
    }

    /**
     * @return iterable<string, array{status: int, location: string}>
     */
    public static function redirectProvider(): iterable
    {
        yield 'same-origin 301' => ['status' => 301, 'location' => 'https://api.example.com/moved'];
        yield 'same-origin 302' => ['status' => 302, 'location' => 'https://api.example.com/moved'];
        yield 'same-origin 307' => ['status' => 307, 'location' => '/moved'];
        yield 'cross-origin 302' => ['status' => 302, 'location' => 'https://evil.example.com/steal'];
        yield 'cross-origin 308' => ['status' => 308, 'location' => 'https://evil.example.com/steal'];
        yield 'downgrade to http' => ['status' => 302, 'location' => 'http://api.example.com/moved'];
        yield 'downgrade cross-origin' => ['status' => 303, 'location' => 'http://evil.example.com/steal'];
    }

    #[DataProvider('redirectProvider')]
    public function test_a_redirect_is_the_terminal_response_and_costs_exactly_one_request(
        int $status,
        string $location,
    ): void {
        $transport = new RecordingTransport([['status' => $status, 'headers' => ['Location' => $location]]]);
        $client = new SigV4SigningClient(
            self::ORIGIN,
            'us-east-1',
            'es',
            new FixedCredentialProvider(
                new \AsyncAws\Core\Credentials\Credentials(self::ACCESS_KEY, 'secret', self::SESSION_TOKEN),
            ),
            null,
            $transport->asTransport(),
        );

        $response = $client->sendRequest(new Request('GET', '/users'));

        self::assertSame($status, $response->getStatusCode());
        self::assertSame($location, $response->getHeaderLine('Location'));
        self::assertSame(1, $transport->callCount());
        self::assertSame('https://api.example.com/users', $transport->urlOfCall(0));
        self::assertSame(0, $transport->optionOfCall(0, 'max_redirects'));
        self::assertStringContainsString(self::ACCESS_KEY, $transport->headerLineOfCall(0, 'Authorization'));
        self::assertSame(self::SESSION_TOKEN, $transport->headerLineOfCall(0, 'X-Amz-Security-Token'));
    }

    /**
     * The default credential chain runs on the same transport, so a
     * container-credentials endpoint answering 3xx gets no follow-up:
     * the pod-identity token is sent to the endpoint that was configured
     * and to nothing a `Location` names.
     */
    public function test_the_default_credential_chain_does_not_follow_a_redirect(): void
    {
        $tokenFile = tempnam(sys_get_temp_dir(), 'kinetis-pod-identity-');
        self::assertIsString($tokenFile);
        file_put_contents($tokenFile, 'POD-IDENTITY-TOKEN-SENTINEL');

        $this->setEnv([
            'AWS_ACCESS_KEY_ID' => '',
            'AWS_SECRET_ACCESS_KEY' => '',
            'AWS_SESSION_TOKEN' => '',
            'AWS_PROFILE' => 'kinetis-absent-profile',
            'AWS_SHARED_CREDENTIALS_FILE' => '/nonexistent/credentials',
            'AWS_CONFIG_FILE' => '/nonexistent/config',
            'AWS_CONTAINER_CREDENTIALS_RELATIVE_URI' => '/v2/credentials/abc',
            'AWS_CONTAINER_AUTHORIZATION_TOKEN_FILE' => $tokenFile,
        ]);

        $transport = new RecordingTransport([
            ['status' => 301, 'headers' => ['Location' => 'https://evil.example.com/steal']],
        ]);

        $client = new SigV4SigningClient(
            self::ORIGIN,
            'us-east-1',
            'es',
            null,
            null,
            $transport->asTransport(),
        );

        try {
            $client->sendRequest(new Request('GET', '/users'));

            self::fail('Expected an UnsignableRequestException to be thrown.');
        } catch (UnsignableRequestException $e) {
            self::assertSame(UnsignableRequestException::CREDENTIALS_UNAVAILABLE, $e->getMessage());
        } finally {
            unlink($tokenFile);
        }

        $urls = array_column($transport->calls, 'url');

        self::assertSame(1, count(array_filter($urls, static fn (string $url): bool
            => $url === 'http://169.254.170.2/v2/credentials/abc')));
        self::assertSame([], array_filter($urls, static fn (string $url): bool
            => str_contains($url, 'evil.example.com')));

        foreach (array_keys($transport->calls) as $index) {
            self::assertSame(0, $transport->optionOfCall($index, 'max_redirects'));
            self::assertSame(
                $urls[$index] === 'http://169.254.170.2/v2/credentials/abc' ? 'POD-IDENTITY-TOKEN-SENTINEL' : '',
                $transport->headerLineOfCall($index, 'Authorization'),
            );
        }
    }

    /**
     * `max_redirects` is written onto every request the transport
     * forwards rather than left in its default options, because a
     * per-request option overrides a default and Symfony's PSR-18
     * adapter builds the option array for the signed request. Neither
     * route puts redirect following back.
     */
    public function test_redirect_following_cannot_be_configured_back_on(): void
    {
        $transport = new RecordingTransport();

        $transport->asTransport()
            ->withOptions(['max_redirects' => 20])
            ->request('GET', 'https://api.example.com/users', ['max_redirects' => 20]);

        self::assertSame(0, $transport->optionOfCall(0, 'max_redirects'));
    }

    /**
     * The default options the transport is built with are the caller's
     * own, with the redirect ceiling written over whatever they say
     * about it — the same ceiling request() then fixes per request, so
     * the delegate is redirect-free before a request reaches it and
     * again as one does.
     */
    public function test_the_delegate_takes_the_callers_options_under_a_fixed_redirect_ceiling(): void
    {
        $options = self::defaultOptionsOf(SignedTransport::create([
            'timeout' => 2.5,
            'max_redirects' => 20,
        ]));

        self::assertSame(0, $options['max_redirects'] ?? null);
        self::assertSame(2.5, $options['timeout'] ?? null);
    }

    /**
     * @return iterable<string, array{options: array<string, mixed>}>
     */
    public static function defaultOptionsProvider(): iterable
    {
        yield 'no options' => ['options' => []];
        yield 'a timeout' => ['options' => ['timeout' => 5.0]];
        yield 'default headers' => ['options' => ['headers' => ['Accept' => 'application/json']]];
    }

    /**
     * What the default transport is built over, and the reason it is
     * built here rather than accepted from a caller.
     *
     * Symfony's `AmpHttpClient` takes a configurator that turns a
     * connection pool into the AMPHP client every request runs through,
     * and its own default wraps the pool in an `InterceptedHttpClient`
     * carrying `RetryRequests(2)`: a signed request would be replayed
     * two levels below the PSR-18 boundary, where no Symfony option and
     * no caller can see it. `SignedTransport` pins a configurator that
     * hands the pool back untouched, so the chain holds no interceptor —
     * no retry, and no `FollowRedirects`, which keeps `Authorization`
     * across a same-authority hop including `https` to `http`.
     *
     * Configuring default options does not reach the configurator.
     *
     * @param array<string, mixed> $options
     */
    #[DataProvider('defaultOptionsProvider')]
    public function test_the_default_transport_runs_on_an_amphp_client_with_no_interceptors(array $options): void
    {
        $pool = new PooledHttpClient();

        self::assertSame($pool, (self::configuratorOf(SignedTransport::create($options)))($pool));

        $stock = (new \ReflectionProperty(AmpHttpClient::class, 'multi'))->getValue(new AmpHttpClient());
        self::assertInstanceOf(AmpClientState::class, $stock);
        $stockConfigurator = (new \ReflectionProperty(AmpClientState::class, 'clientConfigurator'))->getValue($stock);
        self::assertInstanceOf(\Closure::class, $stockConfigurator);

        self::assertInstanceOf(InterceptedHttpClient::class, $stockConfigurator($pool));
    }

    /**
     * The narrowing that keeps a caller's own execution policy out. A
     * transport is constructed by this package — `create()` takes
     * default options and nothing else, and there is no constructor to
     * reach — so neither a wrapping Symfony client nor an AMPHP client
     * configurator of a caller's own has a way in.
     */
    public function test_a_transport_can_only_be_built_from_default_options(): void
    {
        $constructor = new \ReflectionMethod(SignedTransport::class, '__construct');
        self::assertTrue($constructor->isPrivate());

        $create = new \ReflectionMethod(SignedTransport::class, 'create');
        $parameters = $create->getParameters();

        self::assertCount(1, $parameters);
        self::assertSame('defaultOptions', $parameters[0]->getName());
        self::assertSame('array', (string) $parameters[0]->getType());
    }

    /**
     * And the narrowing at the client's own boundary: the transport
     * parameter takes this package's transport, so a decorator that
     * replays or re-options a signed request is a type error rather
     * than a silent substitution.
     */
    public function test_a_retrying_client_cannot_be_substituted_for_the_transport(): void
    {
        $parameter = (new \ReflectionMethod(SigV4SigningClient::class, '__construct'))->getParameters()[5];

        self::assertSame('transport', $parameter->getName());
        self::assertSame('?' . SignedTransport::class, (string) $parameter->getType());

        $this->expectException(\TypeError::class);

        new SigV4SigningClient(
            self::ORIGIN,
            'us-east-1',
            'es',
            FixedCredentialProvider::example(),
            null,
            self::retrying(new RecordingTransport()),
        );
    }

    /**
     * What the narrowing is for. The retrying client, asked directly and
     * with the same `max_redirects => 0` in force, sends twice and
     * answers with the second response: a signed `Authorization` header
     * goes out once per attempt and the caller never sees the 3xx.
     */
    public function test_a_retrying_client_replays_a_request_and_hides_the_first_response(): void
    {
        $transport = new RecordingTransport([
            ['status' => 302, 'headers' => ['Location' => 'https://api.example.com/moved']],
            ['status' => 200, 'body' => 'the second attempt'],
        ]);

        $response = self::retrying($transport)
            ->request('GET', 'https://api.example.com/users', ['max_redirects' => 0]);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(2, $transport->callCount());
    }

    /**
     * The options every request through the delegate starts from, before
     * request() merges the caller's own over them.
     *
     * @return array<string, mixed>
     */
    private static function defaultOptionsOf(SignedTransport $transport): array
    {
        $options = (new \ReflectionProperty(AmpHttpClient::class, 'defaultOptions'))
            ->getValue(self::delegateOf($transport));

        self::assertIsArray($options);

        /** @var array<string, mixed> $options */
        return $options;
    }

    /**
     * Reads the AMPHP client configurator out of the Symfony client a
     * transport delegates to. The pinned configurator is exactly the
     * thing under test, and the alternative is opening a real
     * connection.
     */
    private static function configuratorOf(SignedTransport $transport): \Closure
    {
        $state = (new \ReflectionProperty(AmpHttpClient::class, 'multi'))->getValue(self::delegateOf($transport));
        self::assertInstanceOf(AmpClientState::class, $state);

        $configurator = (new \ReflectionProperty(AmpClientState::class, 'clientConfigurator'))->getValue($state);
        self::assertInstanceOf(\Closure::class, $configurator);

        return $configurator;
    }

    /**
     * Every property these helpers read is private, and Symfony marks
     * `AmpClientState` internal, so reflection is the only way in.
     */
    private static function delegateOf(SignedTransport $transport): AmpHttpClient
    {
        $delegate = (new \ReflectionProperty(SignedTransport::class, 'delegate'))->getValue($transport);
        self::assertInstanceOf(AmpHttpClient::class, $delegate);

        return $delegate;
    }

    private static function retrying(RecordingTransport $transport): RetryableHttpClient
    {
        return new RetryableHttpClient($transport->asMockClient(), new GenericRetryStrategy([302], 0), 1);
    }

    /**
     * @param array<string, string> $values
     */
    private function setEnv(array $values): void
    {
        foreach ($values as $name => $value) {
            $this->originalEnv[$name] = $_ENV[$name] ?? null;
            $_ENV[$name] = $value;
        }
    }
}
