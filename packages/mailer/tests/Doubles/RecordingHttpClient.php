<?php

declare(strict_types=1);

namespace Kinetis\Mailer\Tests\Doubles;

use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

/**
 * Records the options each request is made with, which is the boundary
 * {@see \Kinetis\Mailer\NoRedirectHttpClient} writes `max_redirects` at.
 */
final class RecordingHttpClient implements HttpClientInterface
{
    /** @var list<array{method: string, url: string, options: array<string, mixed>}> */
    public array $requests = [];

    /** @var array<string, mixed> */
    public array $defaults = [];

    #[\Override]
    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        $this->requests[] = ['method' => $method, 'url' => $url, 'options' => $options];

        return new MockResponse('', ['http_code' => 202]);
    }

    #[\Override]
    public function stream(ResponseInterface|iterable $responses, ?float $timeout = null): ResponseStreamInterface
    {
        throw new \LogicException('Not streamed in these tests.');
    }

    #[\Override]
    public function withOptions(array $options): static
    {
        $clone = clone $this;
        $clone->defaults = $options;

        return $clone;
    }
}
