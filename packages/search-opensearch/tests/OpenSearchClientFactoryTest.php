<?php

declare(strict_types=1);

namespace Kinetis\SearchOpenSearch\Tests;

use Kinetis\Config\Config;
use Kinetis\Config\Exception\MissingConfigException;
use Kinetis\SearchOpenSearch\OpenSearchClientFactory;
use OpenSearch\Client;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use ReflectionProperty;
use RuntimeException;
use Symfony\Component\HttpClient\AmpHttpClient;
use Symfony\Component\HttpClient\Psr18Client;

final class OpenSearchClientFactoryTest extends TestCase
{
    public function test_builds_a_client_for_the_default_connection(): void
    {
        $config = new Config(['SEARCH_OPENSEARCH_HOST' => 'http://localhost:9200']);

        $client = OpenSearchClientFactory::fromConfig($config);

        self::assertInstanceOf(Client::class, $client);
        self::assertSame('http://localhost:9200', $this->defaultOptionsOf($client)['base_uri']);
    }

    public function test_a_named_connection_reads_its_own_host_not_the_defaults(): void
    {
        $config = new Config([
            'SEARCH_OPENSEARCH_HOST' => 'http://localhost:9200',
            'SEARCH_LOGS_OPENSEARCH_HOST' => 'http://logs-cluster:9200',
        ]);

        $default = OpenSearchClientFactory::fromConfig($config);
        $logs = OpenSearchClientFactory::fromConfig($config, 'logs');

        self::assertSame('http://localhost:9200', $this->defaultOptionsOf($default)['base_uri']);
        self::assertSame('http://logs-cluster:9200', $this->defaultOptionsOf($logs)['base_uri']);
    }

    public function test_a_missing_host_throws_a_clear_error(): void
    {
        $config = new Config([]);

        $this->expectException(MissingConfigException::class);
        $this->expectExceptionMessage('SEARCH_OPENSEARCH_HOST');
        OpenSearchClientFactory::fromConfig($config);
    }

    public function test_a_named_connections_missing_host_names_its_own_scoped_key(): void
    {
        $config = new Config([]);

        $this->expectException(MissingConfigException::class);
        $this->expectExceptionMessage('SEARCH_LOGS_OPENSEARCH_HOST');
        OpenSearchClientFactory::fromConfig($config, 'logs');
    }

    public function test_basic_auth_is_only_configured_when_a_username_is_given(): void
    {
        $withoutAuth = OpenSearchClientFactory::fromConfig(new Config([
            'SEARCH_OPENSEARCH_HOST' => 'http://localhost:9200',
        ]));
        $withAuth = OpenSearchClientFactory::fromConfig(new Config([
            'SEARCH_OPENSEARCH_HOST' => 'http://localhost:9200',
            'SEARCH_OPENSEARCH_USERNAME' => 'admin',
            'SEARCH_OPENSEARCH_PASSWORD' => 'secret',
        ]));

        self::assertNull($this->defaultOptionsOf($withoutAuth)['auth_basic']);
        // Symfony's HttpClient normalizes the ['user', 'pass'] array form
        // into a colon-joined string internally — confirmed directly
        // rather than assumed from the option's documented input shape.
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

    public function test_a_transport_decorator_wraps_the_fully_configured_client(): void
    {
        $config = new Config([
            'SEARCH_OPENSEARCH_HOST' => 'http://localhost:9200',
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

        $httpTransportProperty = new ReflectionProperty($client, 'httpTransport');
        $httpTransport = $httpTransportProperty->getValue($client);
        $psr18ClientProperty = new ReflectionProperty($httpTransport, 'client');

        // The decorator itself is what TransportFactory actually received.
        self::assertSame($decorated, $psr18ClientProperty->getValue($httpTransport));

        // And it wrapped the real, fully-configured client — auth_basic
        // included — not a bare, unconfigured one.
        self::assertInstanceOf(Psr18Client::class, $decorated->wrapped);
        $ampClientProperty = new ReflectionProperty($decorated->wrapped, 'client');
        /** @var AmpHttpClient $ampClient */
        $ampClient = $ampClientProperty->getValue($decorated->wrapped);
        $defaultOptionsProperty = new ReflectionProperty($ampClient, 'defaultOptions');
        /** @var array<string, mixed> $defaultOptions */
        $defaultOptions = $defaultOptionsProperty->getValue($ampClient);

        self::assertSame('admin:secret', $defaultOptions['auth_basic']);
    }

    public function test_with_no_transport_decorator_the_plain_client_is_used_directly(): void
    {
        $client = OpenSearchClientFactory::fromConfig(new Config([
            'SEARCH_OPENSEARCH_HOST' => 'http://localhost:9200',
        ]));

        $httpTransportProperty = new ReflectionProperty($client, 'httpTransport');
        $httpTransport = $httpTransportProperty->getValue($client);
        $psr18ClientProperty = new ReflectionProperty($httpTransport, 'client');

        self::assertInstanceOf(Psr18Client::class, $psr18ClientProperty->getValue($httpTransport));
    }

    /**
     * @return array<string, mixed>
     */
    private function defaultOptionsOf(Client $client): array
    {
        $httpTransportProperty = new ReflectionProperty($client, 'httpTransport');
        $httpTransport = $httpTransportProperty->getValue($client);

        $psr18ClientProperty = new ReflectionProperty($httpTransport, 'client');

        /** @var Psr18Client $psr18Client */
        $psr18Client = $psr18ClientProperty->getValue($httpTransport);

        $ampClientProperty = new ReflectionProperty($psr18Client, 'client');

        /** @var AmpHttpClient $ampClient */
        $ampClient = $ampClientProperty->getValue($psr18Client);

        $defaultOptionsProperty = new ReflectionProperty($ampClient, 'defaultOptions');

        /** @var array<string, mixed> $defaultOptions */
        $defaultOptions = $defaultOptionsProperty->getValue($ampClient);

        return $defaultOptions;
    }
}
