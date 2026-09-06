<?php

declare(strict_types=1);

namespace Kinetis\Mailer\Tests;

use Amp\Http\Client\InterceptedHttpClient;
use Amp\Http\Client\PooledHttpClient;
use Kinetis\Mailer\Exception\InsecureRequestException;
use Kinetis\Mailer\MailerFactory;
use Kinetis\Mailer\NoRedirectHttpClient;
use Kinetis\Mailer\Tests\Doubles\RecordingHttpClient;
use Kinetis\RevoltHttpClient\AmpHttpClientFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\AmpHttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class NoRedirectHttpClientTest extends TestCase
{
    use RendersThrowables;

    private const string CREDENTIAL = 'Bearer PROVIDERKEY-SENTINEL-9f2a';

    private const string BODY = 'BODYSENTINEL-message-mime-part';

    private const string USERINFO = 'apiuser:URLSECRET-SENTINEL-4b1c';

    /** @var list<resource> */
    private array $servers = [];

    #[\Override]
    protected function tearDown(): void
    {
        foreach ($this->servers as $server) {
            proc_terminate($server);
            proc_close($server);
        }

        $this->servers = [];
    }

    // --- the three invariants -------------------------------------------

    public function test_every_request_carries_the_three_invariants(): void
    {
        $recorder = new RecordingHttpClient();

        new NoRedirectHttpClient($recorder)->request('POST', 'https://api.example.com/mail');

        self::assertSame(0, $recorder->requests[0]['options']['max_redirects']);
        self::assertTrue($recorder->requests[0]['options']['verify_peer']);
        self::assertTrue($recorder->requests[0]['options']['verify_host']);
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>}>
     */
    public static function callerAttempts(): iterable
    {
        yield 'redirects turned back on' => [['max_redirects' => 20]];
        yield 'peer verification turned off' => [['verify_peer' => false]];
        yield 'host verification turned off' => [['verify_host' => false]];
        yield 'all three at once' => [['max_redirects' => 5, 'verify_peer' => 0, 'verify_host' => 0]];
    }

    /**
     * @param array<string, mixed> $options
     */
    #[DataProvider('callerAttempts')]
    public function test_a_per_call_option_cannot_relax_an_invariant(array $options): void
    {
        $recorder = new RecordingHttpClient();

        new NoRedirectHttpClient($recorder)->request('POST', 'https://api.example.com/mail', $options);

        self::assertSame(0, $recorder->requests[0]['options']['max_redirects']);
        self::assertTrue($recorder->requests[0]['options']['verify_peer']);
        self::assertTrue($recorder->requests[0]['options']['verify_host']);
    }

    /**
     * @param array<string, mixed> $options
     */
    #[DataProvider('callerAttempts')]
    public function test_a_derived_client_cannot_relax_an_invariant(array $options): void
    {
        $recorder = new RecordingHttpClient();

        $derived = new NoRedirectHttpClient($recorder)->withOptions($options + ['timeout' => 3]);

        self::assertInstanceOf(NoRedirectHttpClient::class, $derived);

        $inner = $this->innerOf($derived);
        self::assertInstanceOf(RecordingHttpClient::class, $inner);
        self::assertSame(0, $inner->defaults['max_redirects'], 'the derived client carries the rule as a default');
        self::assertTrue($inner->defaults['verify_peer']);
        self::assertTrue($inner->defaults['verify_host']);

        $derived->request('POST', 'https://api.example.com/mail', $options);

        self::assertSame(0, $inner->requests[0]['options']['max_redirects']);
        self::assertTrue($inner->requests[0]['options']['verify_peer']);
        self::assertTrue($inner->requests[0]['options']['verify_host']);
    }

    // --- the HTTPS boundary ------------------------------------------------

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function refusedTargets(): iterable
    {
        yield 'plaintext HTTP' => ['http://api.example.com/mail'];
        yield 'a protocol-relative URL' => ['//api.example.com/mail'];
        yield 'an absolute path' => ['/v3/mail/send'];
        yield 'a relative path' => ['v3/mail/send'];
        yield 'an empty target' => [''];
        yield 'a scheme with no host' => ['https:///v3/mail/send'];
        yield 'a malformed URL' => ['https://api.example.com:not-a-port/mail'];
        yield 'a non-HTTP scheme' => ['file:///etc/passwd'];
        yield 'an FTP endpoint' => ['ftp://api.example.com/mail'];
        yield 'userinfo in the target' => ['https://' . self::USERINFO . '@api.example.com/mail'];
        yield 'a bare user in the target' => ['https://token@api.example.com/mail'];
        yield 'an empty user with a password' => ['https://:secret@api.example.com/mail'];
        yield 'an empty userinfo' => ['https://@api.example.com/mail'];
    }

    #[DataProvider('refusedTargets')]
    public function test_only_an_absolute_https_target_reaches_the_inner_client(string $url): void
    {
        $recorder = new RecordingHttpClient();

        try {
            new NoRedirectHttpClient($recorder)->request('POST', $url, ['body' => self::BODY]);
            self::fail('The target was accepted.');
        } catch (InsecureRequestException $e) {
            self::assertSame([], $recorder->requests, 'nothing reached the inner client');
            self::assertStringNotContainsString('api.example.com', $e->getMessage());
            self::assertStringNotContainsString(self::BODY, (string) $e);
        }
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function acceptedTargets(): iterable
    {
        yield 'a provider endpoint' => ['https://api.sendgrid.com/v3/mail/send'];
        yield 'an uppercase scheme' => ['HTTPS://api.sendgrid.com/v3/mail/send'];
        yield 'a custom endpoint on a port' => ['https://api.eu.example.com:8443/v3/mail/send'];
        yield 'an SES regional endpoint' => ['https://email.eu-west-1.amazonaws.com/v2/email/outbound-emails'];
    }

    #[DataProvider('acceptedTargets')]
    public function test_a_real_provider_endpoint_passes_the_boundary(string $url): void
    {
        $recorder = new RecordingHttpClient();

        new NoRedirectHttpClient($recorder)->request('POST', $url, [
            'headers' => ['Authorization' => self::CREDENTIAL],
            'body' => self::BODY,
        ]);

        self::assertCount(1, $recorder->requests);
        self::assertSame($url, $recorder->requests[0]['url']);
        self::assertSame(self::BODY, $recorder->requests[0]['options']['body']);
    }

    // --- what a refusal carries ----------------------------------------------

    public function test_a_refusal_retains_no_url_header_or_body_in_any_rendering(): void
    {
        // A derived client, so the frames of both the decorator and the
        // one it wraps are the ones a trace could capture.
        $client = new NoRedirectHttpClient(new RecordingHttpClient())
            ->withOptions(['headers' => ['X-Derived' => self::CREDENTIAL]]);

        try {
            $client->request('POST', 'https://' . self::USERINFO . '@api.example.com/mail', [
                'headers' => ['Authorization' => self::CREDENTIAL],
                'body' => self::BODY,
            ]);
            self::fail('The target was accepted.');
        } catch (InsecureRequestException $e) {
            self::assertNull($e->getPrevious());
            $this->assertNoSentinelSurvives($e, [self::USERINFO, self::CREDENTIAL, self::BODY]);
        }
    }

    public function test_every_carrier_parameter_is_marked_sensitive(): void
    {
        $carriers = [
            '__construct' => ['client'],
            'request' => ['url', 'options'],
            'stream' => ['responses'],
            'withOptions' => ['options'],
        ];

        foreach ($carriers as $method => $expected) {
            foreach (new \ReflectionMethod(NoRedirectHttpClient::class, $method)->getParameters() as $parameter) {
                self::assertSame(
                    in_array($parameter->getName(), $expected, true),
                    $parameter->getAttributes(\SensitiveParameter::class) !== [],
                    "{$method}(\${$parameter->getName()})",
                );
            }
        }
    }

    // --- the stack underneath -----------------------------------------------

    public function test_the_client_api_transports_receive_is_the_no_retry_amp_stack(): void
    {
        $client = MailerFactory::httpClient();

        self::assertInstanceOf(NoRedirectHttpClient::class, $client);

        $amp = $this->innerOf($client);
        self::assertInstanceOf(AmpHttpClient::class, $amp);

        // Left to itself AmpHttpClient wraps its pool in a RetryRequests
        // interceptor that repeats a failed request twice more, which
        // would put a mail POST on the wire three times. The configurator
        // is the one place that decision lives, so it is the thing
        // asserted on.
        $state = new \ReflectionProperty(AmpHttpClient::class, 'multi')->getValue($amp);
        self::assertIsObject($state);

        /** @var \Closure $configurator */
        $configurator = new \ReflectionProperty($state, 'clientConfigurator')->getValue($state);
        $configured = $configurator(new PooledHttpClient());

        self::assertInstanceOf(PooledHttpClient::class, $configured);
        self::assertNotInstanceOf(InterceptedHttpClient::class, $configured);
    }

    /**
     * The `max_redirects` the decorator writes is only worth writing if
     * the stack underneath honours it, so that is proven against two real
     * local origins rather than assumed. The decorator itself refuses a
     * plaintext target, which is why the raw client is the one driven
     * here.
     *
     * @return iterable<string, array{0: bool}>
     */
    public static function redirectOrigins(): iterable
    {
        yield 'cross-origin' => [true];
        yield 'same-origin' => [false];
    }

    #[DataProvider('redirectOrigins')]
    public function test_the_option_the_decorator_writes_makes_a_redirect_terminal(bool $crossOrigin): void
    {
        [$entry, $sink, $log] = $this->startRedirectPair($crossOrigin);

        $response = AmpHttpClientFactory::createWithoutRetries()->request('POST', "{$entry}/send", [
            'max_redirects' => 0,
            'headers' => ['Authorization' => self::CREDENTIAL],
            'body' => self::BODY,
        ]);

        self::assertSame(302, $response->getStatusCode(), 'the 3xx is the response, not a step');
        self::assertSame("{$sink}/sink", $response->getHeaders(false)['location'][0]);
        self::assertFileDoesNotExist($log, 'the redirect target received no request at all');
    }

    public function test_the_same_endpoint_does_forward_when_redirects_are_allowed(): void
    {
        // The control for the test above: without the option the request
        // really does reach the second origin, so the assertion there is
        // the rule working rather than a harness that never sends
        // anything.
        [$entry, , $log] = $this->startRedirectPair(crossOrigin: true);

        $response = AmpHttpClientFactory::createWithoutRetries()->request('POST', "{$entry}/send", [
            'headers' => ['Authorization' => self::CREDENTIAL],
            'body' => self::BODY,
        ]);

        self::assertSame(200, $response->getStatusCode());
        self::assertFileExists($log);
    }

    public function test_a_plaintext_redirect_entry_point_never_leaves_the_decorator(): void
    {
        [$entry, , $log] = $this->startRedirectPair(crossOrigin: true);

        try {
            MailerFactory::httpClient()->request('POST', "{$entry}/send", [
                'headers' => ['Authorization' => self::CREDENTIAL],
                'body' => self::BODY,
            ]);
            self::fail('A plaintext endpoint was accepted.');
        } catch (InsecureRequestException) {
            self::assertFileDoesNotExist($log);
        }
    }

    // --- helpers ---------------------------------------------------------------

    private function innerOf(NoRedirectHttpClient $client): HttpClientInterface
    {
        /** @var HttpClientInterface $inner */
        $inner = new \ReflectionProperty(NoRedirectHttpClient::class, 'client')->getValue($client);

        return $inner;
    }

    /**
     * @return array{0: string, 1: string, 2: string}
     */
    private function startRedirectPair(bool $crossOrigin): array
    {
        $log = sys_get_temp_dir() . '/kinetis-mailer-sink-' . bin2hex(random_bytes(6)) . '.log';
        $router = __DIR__ . '/Fixtures/redirect-server.php';

        $sinkPort = $crossOrigin ? $this->freePort() : null;
        $entryPort = $this->freePort();

        if ($sinkPort !== null) {
            $this->startServer($sinkPort, $router, ['SINK_LOG' => $log]);
        }

        $sink = 'http://127.0.0.1:' . ($sinkPort ?? $entryPort);
        $this->startServer($entryPort, $router, ['SINK_LOG' => $log, 'REDIRECT_TO' => "{$sink}/sink"]);

        return ["http://127.0.0.1:{$entryPort}", $sink, $log];
    }

    /**
     * @param array<string, string> $env
     */
    private function startServer(int $port, string $router, array $env): void
    {
        $process = proc_open(
            [PHP_BINARY, '-S', "127.0.0.1:{$port}", '-t', dirname($router), $router],
            [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
            null,
            $env + ['PATH' => getenv('PATH')],
        );

        if (!is_resource($process)) {
            self::markTestSkipped('This environment cannot start a local HTTP server.');
        }

        $this->servers[] = $process;

        for ($attempt = 0; $attempt < 100; ++$attempt) {
            $socket = @fsockopen('127.0.0.1', $port, $errno, $error, 0.1);

            if (is_resource($socket)) {
                fclose($socket);

                return;
            }

            usleep(50_000);
        }

        self::markTestSkipped('The local HTTP server did not come up.');
    }

    private function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);

        if ($socket === false) {
            self::markTestSkipped('This environment cannot bind a loopback port.');
        }

        $name = stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr((string) $name, strrpos((string) $name, ':') + 1);
    }
}
