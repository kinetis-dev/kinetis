<?php

declare(strict_types=1);

namespace Kinetis\SearchOpenSearch\Tests;

use Kinetis\Config\Config;
use Kinetis\Config\Exception\MissingConfigException;
use Kinetis\Search\BufferedHttpClient;
use Kinetis\Search\Exception\SearchConfigurationException;
use Kinetis\SearchOpenSearch\OpenSearchClientFactory;
use OpenSearch\Client;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use ReflectionProperty;
use RuntimeException;

/**
 * What this package owns: the configuration prefix it reads under, and
 * the transport reaching OpenSearch's own TransportFactory. The origin,
 * deadline, response bound, credential and TLS policy belong to
 * kinetis/search and are proven there.
 */
final class OpenSearchClientFactoryTest extends TestCase
{
    public function test_builds_a_client_over_the_packages_own_transport(): void
    {
        $client = OpenSearchClientFactory::fromConfig(
            new Config(['SEARCH_OPENSEARCH_HOST' => 'https://localhost:9200']),
        );

        self::assertInstanceOf(Client::class, $client);
        self::assertInstanceOf(BufferedHttpClient::class, $this->psr18ClientOf($client));
    }

    public function test_the_configuration_prefix_is_this_engines_own(): void
    {
        self::assertSame('SEARCH_OPENSEARCH', OpenSearchClientFactory::CONFIG_PREFIX);

        $this->expectException(MissingConfigException::class);
        $this->expectExceptionMessage('SEARCH_OPENSEARCH_HOST');
        OpenSearchClientFactory::fromConfig(new Config([]));
    }

    public function test_a_named_connection_reads_its_own_scoped_keys(): void
    {
        $this->expectException(MissingConfigException::class);
        $this->expectExceptionMessage('SEARCH_LOGS_OPENSEARCH_HOST');
        OpenSearchClientFactory::fromConfig(new Config([]), 'logs');
    }

    public function test_unusable_configuration_is_refused_while_building(): void
    {
        $this->expectException(SearchConfigurationException::class);
        $this->expectExceptionMessage('SEARCH_OPENSEARCH_PLAINTEXT');
        OpenSearchClientFactory::fromConfig(new Config([
            'SEARCH_OPENSEARCH_HOST' => 'http://localhost:9200',
        ]));
    }

    public function test_a_transport_decorator_is_what_the_official_transport_receives(): void
    {
        $decorated = new class implements ClientInterface {
            public ?ClientInterface $wrapped = null;

            #[\Override]
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                throw new RuntimeException('never called by this test');
            }
        };

        $client = OpenSearchClientFactory::fromConfig(
            new Config(['SEARCH_OPENSEARCH_HOST' => 'https://localhost:9200']),
            transportDecorator: static function (ClientInterface $inner) use ($decorated): ClientInterface {
                $decorated->wrapped = $inner;

                return $decorated;
            },
        );

        self::assertSame($decorated, $this->psr18ClientOf($client));
        self::assertInstanceOf(BufferedHttpClient::class, $decorated->wrapped);
    }

    private function psr18ClientOf(Client $client): ClientInterface
    {
        $httpTransport = new ReflectionProperty($client, 'httpTransport')->getValue($client);

        /** @var ClientInterface $psr18Client */
        $psr18Client = new ReflectionProperty($httpTransport, 'client')->getValue($httpTransport);

        return $psr18Client;
    }
}
