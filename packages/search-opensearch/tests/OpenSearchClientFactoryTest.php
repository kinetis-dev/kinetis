<?php

declare(strict_types=1);

namespace Kinetis\SearchOpenSearch\Tests;

use Closure;
use Kinetis\Config\Config;
use Kinetis\Config\Exception\MissingConfigException;
use Kinetis\SearchOpenSearch\Exception\OpenSearchConfigurationException;
use Kinetis\SearchOpenSearch\OpenSearchClientFactory;
use Kinetis\SearchOpenSearch\OpenSearchHttpClient;
use OpenSearch\Client;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use ReflectionProperty;
use RuntimeException;
use Symfony\Component\HttpClient\AmpHttpClient;

final class OpenSearchClientFactoryTest extends TestCase
{
    public function test_builds_a_client_for_the_default_connection(): void
    {
        $config = new Config(['SEARCH_OPENSEARCH_HOST' => 'https://localhost:9200']);

        $client = OpenSearchClientFactory::fromConfig($config);

        self::assertInstanceOf(Client::class, $client);
        self::assertSame('https://localhost:9200', $this->defaultOptionsOf($client)['base_uri']);
    }

    public function test_a_named_connection_reads_its_own_host_not_the_defaults(): void
    {
        $config = new Config([
            'SEARCH_OPENSEARCH_HOST' => 'https://localhost:9200',
            'SEARCH_LOGS_OPENSEARCH_HOST' => 'https://logs-cluster:9200',
        ]);

        $default = OpenSearchClientFactory::fromConfig($config);
        $logs = OpenSearchClientFactory::fromConfig($config, 'logs');

        self::assertSame('https://localhost:9200', $this->defaultOptionsOf($default)['base_uri']);
        self::assertSame('https://logs-cluster:9200', $this->defaultOptionsOf($logs)['base_uri']);
    }

    public function test_a_missing_host_throws_a_clear_error(): void
    {
        $this->expectException(MissingConfigException::class);
        $this->expectExceptionMessage('SEARCH_OPENSEARCH_HOST');
        OpenSearchClientFactory::fromConfig(new Config([]));
    }

    public function test_a_named_connections_missing_host_names_its_own_scoped_key(): void
    {
        $this->expectException(MissingConfigException::class);
        $this->expectExceptionMessage('SEARCH_LOGS_OPENSEARCH_HOST');
        OpenSearchClientFactory::fromConfig(new Config([]), 'logs');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function rejectedHosts(): iterable
    {
        yield 'userinfo' => ['https://admin:secret@localhost:9200'];
        yield 'user without password' => ['https://admin@localhost:9200'];
        yield 'base path' => ['https://localhost:9200/search'];
        yield 'query string' => ['https://localhost:9200/?pretty=true'];
        yield 'fragment' => ['https://localhost:9200/#cluster'];
        yield 'no scheme' => ['localhost:9200'];
        yield 'unsupported scheme' => ['ftp://localhost:9200'];
        yield 'no host' => ['https://'];
        yield 'port out of range' => ['https://localhost:99999'];
        yield 'non-numeric port' => ['https://localhost:nine'];
        yield 'empty' => [' '];
    }

    #[DataProvider('rejectedHosts')]
    public function test_a_host_that_is_not_one_origin_is_refused(string $host): void
    {
        $this->expectException(OpenSearchConfigurationException::class);
        $this->expectExceptionMessage('SEARCH_OPENSEARCH_HOST');
        OpenSearchClientFactory::fromConfig(new Config(['SEARCH_OPENSEARCH_HOST' => $host]));
    }

    /**
     * The reason names the key. It never quotes the value, which is where
     * a userinfo credential would be.
     */
    public function test_a_refused_host_is_not_quoted_back(): void
    {
        try {
            OpenSearchClientFactory::fromConfig(new Config([
                'SEARCH_OPENSEARCH_HOST' => 'https://admin:secret@localhost:9200',
            ]));
            self::fail('the host should have been refused');
        } catch (OpenSearchConfigurationException $e) {
            self::assertStringNotContainsString('secret', $e->getMessage());
            self::assertStringNotContainsString('localhost', $e->getMessage());
        }
    }

    public function test_plaintext_is_refused_until_it_is_opted_into(): void
    {
        $this->expectException(OpenSearchConfigurationException::class);
        $this->expectExceptionMessage('SEARCH_OPENSEARCH_PLAINTEXT');
        OpenSearchClientFactory::fromConfig(new Config([
            'SEARCH_OPENSEARCH_HOST' => 'http://localhost:9200',
        ]));
    }

    public function test_plaintext_is_accepted_once_opted_into(): void
    {
        $client = OpenSearchClientFactory::fromConfig(new Config([
            'SEARCH_OPENSEARCH_HOST' => 'http://localhost:9200',
            'SEARCH_OPENSEARCH_PLAINTEXT' => 'true',
        ]));

        self::assertSame('http://localhost:9200', $this->defaultOptionsOf($client)['base_uri']);
    }

    public function test_the_plaintext_opt_in_does_not_reach_a_named_connection(): void
    {
        $this->expectException(OpenSearchConfigurationException::class);
        $this->expectExceptionMessage('SEARCH_LOGS_OPENSEARCH_PLAINTEXT');
        OpenSearchClientFactory::fromConfig(
            new Config([
                'SEARCH_LOGS_OPENSEARCH_HOST' => 'http://logs-cluster:9200',
                'SEARCH_OPENSEARCH_PLAINTEXT' => 'true',
            ]),
            'logs',
        );
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function normalizedOrigins(): iterable
    {
        yield 'scheme and host lowercased' => ['HTTPS://OpenSearch.Example:9200', 'https://opensearch.example:9200'];
        yield 'no port stays without one' => ['https://opensearch.example', 'https://opensearch.example'];
        yield 'a root path is dropped' => ['https://opensearch.example:9200/', 'https://opensearch.example:9200'];
        yield 'bracketed IPv6 keeps its brackets' => ['https://[2001:db8::1]:9200', 'https://[2001:db8::1]:9200'];
        yield 'bracketed IPv6 loopback' => ['https://[::1]', 'https://[::1]'];
    }

    #[DataProvider('normalizedOrigins')]
    public function test_an_accepted_host_is_rebuilt_as_a_normalized_origin(string $host, string $expected): void
    {
        $client = OpenSearchClientFactory::fromConfig(new Config(['SEARCH_OPENSEARCH_HOST' => $host]));

        self::assertSame($expected, $this->defaultOptionsOf($client)['base_uri']);
    }

    public function test_the_transport_carries_one_deadline_no_redirect_and_identity_encoding(): void
    {
        $options = $this->defaultOptionsOf(OpenSearchClientFactory::fromConfig(new Config([
            'SEARCH_OPENSEARCH_HOST' => 'https://localhost:9200',
        ])));

        self::assertSame(30.0, $options['timeout']);
        self::assertSame(30.0, $options['max_duration']);
        self::assertSame(0, $options['max_redirects']);
        self::assertContains('Accept-Encoding: identity', $options['headers']);
        self::assertContains('Content-Type: application/json', $options['headers']);
        self::assertContains('Accept: application/json', $options['headers']);
        self::assertInstanceOf(Closure::class, $options['on_progress']);
    }

    public function test_the_deadline_is_scoped_and_configurable(): void
    {
        $options = $this->defaultOptionsOf(OpenSearchClientFactory::fromConfig(
            new Config([
                'SEARCH_LOGS_OPENSEARCH_HOST' => 'https://logs-cluster:9200',
                'SEARCH_OPENSEARCH_TIMEOUT' => '1.5',
                'SEARCH_LOGS_OPENSEARCH_TIMEOUT' => '2.5',
            ]),
            'logs',
        ));

        self::assertSame(2.5, $options['timeout']);
        self::assertSame(2.5, $options['max_duration']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function nonPositiveTimeouts(): iterable
    {
        yield 'zero' => ['0'];
        yield 'negative' => ['-1'];
    }

    #[DataProvider('nonPositiveTimeouts')]
    public function test_a_non_positive_timeout_is_refused(string $timeout): void
    {
        $this->expectException(OpenSearchConfigurationException::class);
        $this->expectExceptionMessage('SEARCH_OPENSEARCH_TIMEOUT');
        OpenSearchClientFactory::fromConfig(new Config([
            'SEARCH_OPENSEARCH_HOST' => 'https://localhost:9200',
            'SEARCH_OPENSEARCH_TIMEOUT' => $timeout,
        ]));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function nonPositiveResponseLimits(): iterable
    {
        yield 'zero' => ['0'];
        yield 'negative' => ['-8'];
    }

    #[DataProvider('nonPositiveResponseLimits')]
    public function test_a_non_positive_response_limit_is_refused(string $limit): void
    {
        $this->expectException(OpenSearchConfigurationException::class);
        $this->expectExceptionMessage('SEARCH_OPENSEARCH_MAX_RESPONSE_BYTES');
        OpenSearchClientFactory::fromConfig(new Config([
            'SEARCH_OPENSEARCH_HOST' => 'https://localhost:9200',
            'SEARCH_OPENSEARCH_MAX_RESPONSE_BYTES' => $limit,
        ]));
    }

    public function test_the_response_bound_defaults_to_eight_mebibytes(): void
    {
        $guard = $this->responseBoundOf(new Config(['SEARCH_OPENSEARCH_HOST' => 'https://localhost:9200']));

        $guard(8_388_608, -1, []);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('SEARCH_OPENSEARCH_MAX_RESPONSE_BYTES');
        $guard(8_388_609, -1, []);
    }

    public function test_the_response_bound_is_scoped_and_configurable(): void
    {
        $guard = $this->responseBoundOf(
            new Config([
                'SEARCH_LOGS_OPENSEARCH_HOST' => 'https://logs-cluster:9200',
                'SEARCH_OPENSEARCH_MAX_RESPONSE_BYTES' => '1024',
                'SEARCH_LOGS_OPENSEARCH_MAX_RESPONSE_BYTES' => '64',
            ]),
            'logs',
        );

        $guard(64, 64, []);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('SEARCH_LOGS_OPENSEARCH_MAX_RESPONSE_BYTES');
        $guard(65, 64, []);
    }

    public function test_basic_auth_is_only_configured_when_a_username_is_given(): void
    {
        $withoutAuth = OpenSearchClientFactory::fromConfig(new Config([
            'SEARCH_OPENSEARCH_HOST' => 'https://localhost:9200',
        ]));
        $withAuth = OpenSearchClientFactory::fromConfig(new Config([
            'SEARCH_OPENSEARCH_HOST' => 'https://localhost:9200',
            'SEARCH_OPENSEARCH_USERNAME' => 'admin',
            'SEARCH_OPENSEARCH_PASSWORD' => 'secret',
        ]));

        self::assertNull($this->defaultOptionsOf($withoutAuth)['auth_basic']);
        // Symfony's HttpClient normalizes the ['user', 'pass'] array form
        // into a colon-joined string internally.
        self::assertSame('admin:secret', $this->defaultOptionsOf($withAuth)['auth_basic']);
    }

    public function test_peer_verification_defaults_to_true_and_can_be_disabled(): void
    {
        $default = OpenSearchClientFactory::fromConfig(new Config([
            'SEARCH_OPENSEARCH_HOST' => 'https://localhost:9200',
        ]));
        $disabled = OpenSearchClientFactory::fromConfig(new Config([
            'SEARCH_OPENSEARCH_HOST' => 'https://localhost:9200',
            'SEARCH_OPENSEARCH_VERIFY_PEER' => 'false',
        ]));

        self::assertTrue($this->defaultOptionsOf($default)['verify_peer']);
        self::assertFalse($this->defaultOptionsOf($disabled)['verify_peer']);
    }

    public function test_a_transport_decorator_wraps_this_packages_own_adapter(): void
    {
        $config = new Config([
            'SEARCH_OPENSEARCH_HOST' => 'https://localhost:9200',
            'SEARCH_OPENSEARCH_USERNAME' => 'admin',
            'SEARCH_OPENSEARCH_PASSWORD' => 'secret',
        ]);

        $decorated = new class implements ClientInterface {
            public ?ClientInterface $wrapped = null;

            #[\Override]
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                throw new RuntimeException('never called by this test');
            }
        };

        $client = OpenSearchClientFactory::fromConfig(
            $config,
            transportDecorator: static function (ClientInterface $inner) use ($decorated): ClientInterface {
                $decorated->wrapped = $inner;

                return $decorated;
            },
        );

        // The decorator itself is what TransportFactory received.
        self::assertSame($decorated, $this->psr18ClientOf($client));

        // And it wrapped the package's own adapter over the fully
        // configured transport — auth_basic included.
        self::assertInstanceOf(OpenSearchHttpClient::class, $decorated->wrapped);
        self::assertSame('admin:secret', $this->defaultOptionsOfAdapter($decorated->wrapped)['auth_basic']);
    }

    public function test_with_no_transport_decorator_the_adapter_is_used_directly(): void
    {
        $client = OpenSearchClientFactory::fromConfig(new Config([
            'SEARCH_OPENSEARCH_HOST' => 'https://localhost:9200',
        ]));

        self::assertInstanceOf(OpenSearchHttpClient::class, $this->psr18ClientOf($client));
    }

    private function psr18ClientOf(Client $client): ClientInterface
    {
        $httpTransport = new ReflectionProperty($client, 'httpTransport')->getValue($client);

        /** @var ClientInterface $psr18Client */
        $psr18Client = new ReflectionProperty($httpTransport, 'client')->getValue($httpTransport);

        return $psr18Client;
    }

    /**
     * @return array<string, mixed>
     */
    private function defaultOptionsOf(Client $client): array
    {
        $psr18Client = $this->psr18ClientOf($client);
        self::assertInstanceOf(OpenSearchHttpClient::class, $psr18Client);

        return $this->defaultOptionsOfAdapter($psr18Client);
    }

    /**
     * @return array<string, mixed>
     */
    private function defaultOptionsOfAdapter(OpenSearchHttpClient $adapter): array
    {
        /** @var AmpHttpClient $ampClient */
        $ampClient = new ReflectionProperty($adapter, 'client')->getValue($adapter);

        /** @var array<string, mixed> $defaultOptions */
        $defaultOptions = new ReflectionProperty($ampClient, 'defaultOptions')->getValue($ampClient);

        return $defaultOptions;
    }

    private function responseBoundOf(Config $config, string $connection = 'default'): Closure
    {
        $options = $this->defaultOptionsOf(OpenSearchClientFactory::fromConfig($config, $connection));

        /** @var Closure $guard */
        $guard = $options['on_progress'];

        return $guard;
    }
}
