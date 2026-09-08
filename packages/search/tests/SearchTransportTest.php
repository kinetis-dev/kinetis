<?php

declare(strict_types=1);

namespace Kinetis\Search\Tests;

use Closure;
use Kinetis\Config\Config;
use Kinetis\Config\Exception\MissingConfigException;
use Kinetis\Search\BufferedHttpClient;
use Kinetis\Search\Exception\SearchConfigurationException;
use Kinetis\Search\SearchTransport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use ReflectionProperty;
use RuntimeException;
use Symfony\Component\HttpClient\AmpHttpClient;

/**
 * The prefix is a stand-in for a real engine's own — SEARCH_OPENSEARCH,
 * SEARCH_ELASTICSEARCH — so nothing here reads as one engine's policy.
 */
final class SearchTransportTest extends TestCase
{
    private const string PREFIX = 'SEARCH_ENGINE';

    public function test_builds_a_transport_for_the_default_connection(): void
    {
        $transport = SearchTransport::fromConfig(
            new Config(['SEARCH_ENGINE_HOST' => 'https://localhost:9200']),
            self::PREFIX,
        );

        self::assertSame('https://localhost:9200', $transport->origin);
        self::assertInstanceOf(BufferedHttpClient::class, $transport->client);
        self::assertSame('https://localhost:9200', $this->optionsOf($transport)['base_uri']);
    }

    public function test_a_named_connection_reads_its_own_host_not_the_defaults(): void
    {
        $config = new Config([
            'SEARCH_ENGINE_HOST' => 'https://localhost:9200',
            'SEARCH_LOGS_ENGINE_HOST' => 'https://logs-cluster:9200',
        ]);

        self::assertSame('https://localhost:9200', SearchTransport::fromConfig($config, self::PREFIX)->origin);
        self::assertSame('https://logs-cluster:9200', SearchTransport::fromConfig($config, self::PREFIX, 'logs')->origin);
    }

    public function test_a_missing_host_throws_a_clear_error(): void
    {
        $this->expectException(MissingConfigException::class);
        $this->expectExceptionMessage('SEARCH_ENGINE_HOST');
        SearchTransport::fromConfig(new Config([]), self::PREFIX);
    }

    public function test_a_named_connections_missing_host_names_its_own_scoped_key(): void
    {
        $this->expectException(MissingConfigException::class);
        $this->expectExceptionMessage('SEARCH_LOGS_ENGINE_HOST');
        SearchTransport::fromConfig(new Config([]), self::PREFIX, 'logs');
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
        $this->expectException(SearchConfigurationException::class);
        $this->expectExceptionMessage('SEARCH_ENGINE_HOST');
        SearchTransport::fromConfig(new Config(['SEARCH_ENGINE_HOST' => $host]), self::PREFIX);
    }

    /**
     * The reason names the key. It never quotes the value, which is where
     * a userinfo credential would be.
     */
    public function test_a_refused_host_is_not_quoted_back(): void
    {
        try {
            SearchTransport::fromConfig(
                new Config(['SEARCH_ENGINE_HOST' => 'https://admin:secret@localhost:9200']),
                self::PREFIX,
            );
            self::fail('the host should have been refused');
        } catch (SearchConfigurationException $e) {
            self::assertStringNotContainsString('secret', $e->getMessage());
            self::assertStringNotContainsString('localhost', $e->getMessage());
        }
    }

    public function test_plaintext_is_refused_until_it_is_opted_into(): void
    {
        $this->expectException(SearchConfigurationException::class);
        $this->expectExceptionMessage('SEARCH_ENGINE_PLAINTEXT');
        SearchTransport::fromConfig(new Config(['SEARCH_ENGINE_HOST' => 'http://localhost:9200']), self::PREFIX);
    }

    public function test_plaintext_is_accepted_once_opted_into(): void
    {
        $transport = SearchTransport::fromConfig(
            new Config([
                'SEARCH_ENGINE_HOST' => 'http://localhost:9200',
                'SEARCH_ENGINE_PLAINTEXT' => 'true',
            ]),
            self::PREFIX,
        );

        self::assertSame('http://localhost:9200', $transport->origin);
    }

    public function test_the_plaintext_opt_in_does_not_reach_a_named_connection(): void
    {
        $this->expectException(SearchConfigurationException::class);
        $this->expectExceptionMessage('SEARCH_LOGS_ENGINE_PLAINTEXT');
        SearchTransport::fromConfig(
            new Config([
                'SEARCH_LOGS_ENGINE_HOST' => 'http://logs-cluster:9200',
                'SEARCH_ENGINE_PLAINTEXT' => 'true',
            ]),
            self::PREFIX,
            'logs',
        );
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function normalizedOrigins(): iterable
    {
        yield 'scheme and host lowercased' => ['HTTPS://Search.Example:9200', 'https://search.example:9200'];
        yield 'no port stays without one' => ['https://search.example', 'https://search.example'];
        yield 'a root path is dropped' => ['https://search.example:9200/', 'https://search.example:9200'];
        yield 'bracketed IPv6 keeps its brackets' => ['https://[2001:db8::1]:9200', 'https://[2001:db8::1]:9200'];
        yield 'bracketed IPv6 loopback' => ['https://[::1]', 'https://[::1]'];
    }

    #[DataProvider('normalizedOrigins')]
    public function test_an_accepted_host_is_rebuilt_as_a_normalized_origin(string $host, string $expected): void
    {
        self::assertSame(
            $expected,
            SearchTransport::fromConfig(new Config(['SEARCH_ENGINE_HOST' => $host]), self::PREFIX)->origin,
        );
    }

    public function test_the_transport_carries_one_deadline_no_redirect_and_a_json_content_type(): void
    {
        $options = $this->optionsOf(SearchTransport::fromConfig(
            new Config(['SEARCH_ENGINE_HOST' => 'https://localhost:9200']),
            self::PREFIX,
        ));

        self::assertSame(30.0, $options['timeout']);
        self::assertSame(30.0, $options['max_duration']);
        self::assertSame(0, $options['max_redirects']);
        self::assertContains('Content-Type: application/json', $options['headers']);
        self::assertContains('Accept: application/json', $options['headers']);
        self::assertInstanceOf(Closure::class, $options['on_progress']);
    }

    public function test_the_deadline_is_scoped_and_configurable(): void
    {
        $options = $this->optionsOf(SearchTransport::fromConfig(
            new Config([
                'SEARCH_LOGS_ENGINE_HOST' => 'https://logs-cluster:9200',
                'SEARCH_ENGINE_TIMEOUT' => '1.5',
                'SEARCH_LOGS_ENGINE_TIMEOUT' => '2.5',
            ]),
            self::PREFIX,
            'logs',
        ));

        self::assertSame(2.5, $options['timeout']);
        self::assertSame(2.5, $options['max_duration']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function nonPositiveNumbers(): iterable
    {
        yield 'zero' => ['0'];
        yield 'negative' => ['-1'];
    }

    #[DataProvider('nonPositiveNumbers')]
    public function test_a_non_positive_timeout_is_refused(string $timeout): void
    {
        $this->expectException(SearchConfigurationException::class);
        $this->expectExceptionMessage('SEARCH_ENGINE_TIMEOUT');
        SearchTransport::fromConfig(
            new Config([
                'SEARCH_ENGINE_HOST' => 'https://localhost:9200',
                'SEARCH_ENGINE_TIMEOUT' => $timeout,
            ]),
            self::PREFIX,
        );
    }

    #[DataProvider('nonPositiveNumbers')]
    public function test_a_non_positive_response_limit_is_refused(string $limit): void
    {
        $this->expectException(SearchConfigurationException::class);
        $this->expectExceptionMessage('SEARCH_ENGINE_MAX_RESPONSE_BYTES');
        SearchTransport::fromConfig(
            new Config([
                'SEARCH_ENGINE_HOST' => 'https://localhost:9200',
                'SEARCH_ENGINE_MAX_RESPONSE_BYTES' => $limit,
            ]),
            self::PREFIX,
        );
    }

    public function test_the_response_bound_defaults_to_eight_mebibytes(): void
    {
        $guard = $this->responseBoundOf(new Config(['SEARCH_ENGINE_HOST' => 'https://localhost:9200']));

        $guard(8_388_608, -1, []);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('SEARCH_ENGINE_MAX_RESPONSE_BYTES');
        $guard(8_388_609, -1, []);
    }

    public function test_the_response_bound_is_scoped_and_configurable(): void
    {
        $guard = $this->responseBoundOf(
            new Config([
                'SEARCH_LOGS_ENGINE_HOST' => 'https://logs-cluster:9200',
                'SEARCH_ENGINE_MAX_RESPONSE_BYTES' => '1024',
                'SEARCH_LOGS_ENGINE_MAX_RESPONSE_BYTES' => '64',
            ]),
            'logs',
        );

        $guard(64, 64, []);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('SEARCH_LOGS_ENGINE_MAX_RESPONSE_BYTES');
        $guard(65, 64, []);
    }

    public function test_basic_auth_is_only_configured_when_a_username_is_given(): void
    {
        $withoutAuth = SearchTransport::fromConfig(
            new Config(['SEARCH_ENGINE_HOST' => 'https://localhost:9200']),
            self::PREFIX,
        );
        $withAuth = SearchTransport::fromConfig(
            new Config([
                'SEARCH_ENGINE_HOST' => 'https://localhost:9200',
                'SEARCH_ENGINE_USERNAME' => 'admin',
                'SEARCH_ENGINE_PASSWORD' => 'secret',
            ]),
            self::PREFIX,
        );

        self::assertNull($this->optionsOf($withoutAuth)['auth_basic']);
        // Symfony's HttpClient normalizes the ['user', 'pass'] array form
        // into a colon-joined string internally.
        self::assertSame('admin:secret', $this->optionsOf($withAuth)['auth_basic']);
    }

    public function test_peer_verification_defaults_to_true_and_can_be_disabled(): void
    {
        $default = SearchTransport::fromConfig(
            new Config(['SEARCH_ENGINE_HOST' => 'https://localhost:9200']),
            self::PREFIX,
        );
        $disabled = SearchTransport::fromConfig(
            new Config([
                'SEARCH_ENGINE_HOST' => 'https://localhost:9200',
                'SEARCH_ENGINE_VERIFY_PEER' => 'false',
            ]),
            self::PREFIX,
        );

        self::assertTrue($this->optionsOf($default)['verify_peer']);
        self::assertFalse($this->optionsOf($disabled)['verify_peer']);
    }

    public function test_a_decorator_wraps_the_fully_configured_adapter(): void
    {
        $decorated = new class implements ClientInterface {
            public ?ClientInterface $wrapped = null;

            #[\Override]
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                throw new RuntimeException('never called by this test');
            }
        };

        $transport = SearchTransport::fromConfig(
            new Config([
                'SEARCH_ENGINE_HOST' => 'https://localhost:9200',
                'SEARCH_ENGINE_USERNAME' => 'admin',
                'SEARCH_ENGINE_PASSWORD' => 'secret',
            ]),
            self::PREFIX,
            decorator: static function (ClientInterface $inner) use ($decorated): ClientInterface {
                $decorated->wrapped = $inner;

                return $decorated;
            },
        );

        self::assertSame($decorated, $transport->client);
        self::assertInstanceOf(BufferedHttpClient::class, $decorated->wrapped);
        self::assertSame('admin:secret', $this->optionsOfAdapter($decorated->wrapped)['auth_basic']);
    }

    /**
     * @return array<string, mixed>
     */
    private function optionsOf(SearchTransport $transport): array
    {
        self::assertInstanceOf(BufferedHttpClient::class, $transport->client);

        return $this->optionsOfAdapter($transport->client);
    }

    /**
     * @return array<string, mixed>
     */
    private function optionsOfAdapter(BufferedHttpClient $adapter): array
    {
        /** @var AmpHttpClient $ampClient */
        $ampClient = new ReflectionProperty($adapter, 'client')->getValue($adapter);

        /** @var array<string, mixed> $defaultOptions */
        $defaultOptions = new ReflectionProperty($ampClient, 'defaultOptions')->getValue($ampClient);

        return $defaultOptions;
    }

    private function responseBoundOf(Config $config, string $connection = 'default'): Closure
    {
        /** @var Closure $guard */
        $guard = $this->optionsOf(SearchTransport::fromConfig($config, self::PREFIX, $connection))['on_progress'];

        return $guard;
    }
}
