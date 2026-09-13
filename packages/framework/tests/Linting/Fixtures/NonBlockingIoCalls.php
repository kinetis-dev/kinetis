<?php

declare(strict_types=1);

namespace App;

use Http\Discovery\Psr18Client as DiscoveredPsr18Client;
use Kinetis\Async\Timer;
use Kinetis\RevoltHttpClient\AmpHttpClientFactory;
use Symfony\Component\HttpClient\Psr18Client;

function sleep(int $seconds): void
{
}

function nonBlockingIoCalls(object $transport, mixed $multi, mixed $statement, string $function): void
{
    sleep(1);
    Timer::delay(0.1);
    new DiscoveredPsr18Client($transport);
    new DiscoveredPsr18Client(client: $transport);
    new \Symfony\Component\HttpClient\HttplugClient($transport);
    new \Symfony\Component\HttpClient\HttplugClient(client: $transport);
    new Psr18Client($transport);
    new Psr18Client(client: $transport);
    AmpHttpClientFactory::create();
    curl_multi_exec($multi, $running);
    $statement->exec('SELECT 1');
    $function(1);
    strlen('ordinary');
    new \ArrayObject();
}
