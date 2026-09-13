<?php

declare(strict_types=1);

namespace Kinetis\Tests\Linting\Fixtures;

use GuzzleHttp\Client as GuzzleClient;
use Http\Discovery\HttpAsyncClientDiscovery;
use Http\Discovery\HttpClientDiscovery;
use Http\Discovery\Psr18Client as DiscoveredPsr18Client;
use Http\Discovery\Psr18ClientDiscovery;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\Psr18Client;

use function usleep as pause;

final class BlockingIoCalls
{
    public function sleeps(): void
    {
        sleep(1);
        pause(1);
        \time_nanosleep(0, 1);
        time_sleep_until(1.0);
    }

    public function sockets(mixed $curl, mixed $multi): void
    {
        fsockopen('localhost');
        pfsockopen('localhost');
        stream_socket_client('tcp://localhost:80');
        curl_exec($curl);
        curl_multi_select($multi);
    }

    public function databases(): void
    {
        new \PDO('sqlite::memory:');
        new \mysqli('localhost');
        mysqli_connect('localhost');
        pg_connect('host=localhost');
        pg_pconnect('host=localhost');
    }

    public function processes(): void
    {
        exec('true');
        shell_exec('true');
        system('true');
        passthru('true');
        proc_open('true', [], $pipes);
        popen('true', 'r');
    }

    public function httpClients(): void
    {
        Psr18ClientDiscovery::find();
        HttpClientDiscovery::find();
        HttpAsyncClientDiscovery::find();
        new DiscoveredPsr18Client();
        new DiscoveredPsr18Client(null);
        new DiscoveredPsr18Client(requestFactory: null);
        HttpClient::create();
        HttpClient::createForBaseUri('https://example.com');
        new \Symfony\Component\HttpClient\HttplugClient();
        new \Symfony\Component\HttpClient\HttplugClient(client: null);
        new \Symfony\Component\HttpClient\HttplugClient(responseFactory: null);
        new Psr18Client();
        new Psr18Client(client: NULL);
        new Psr18Client(responseFactory: null);
        new GuzzleClient();
    }
}
