<?php

declare(strict_types=1);

namespace Kinetis\Neuron\Tests\Fixtures\Bedrock;

use Closure;
use Kinetis\AwsSigV4\SignedTransport;
use Kinetis\AwsSigV4\SigV4SigningClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * A signing client for `https://bedrock-runtime.us-east-1.amazonaws.com`
 * whose transport answers in process, in order, from the given responses,
 * and records each request it was handed.
 */
final class RecordingBedrock
{
    public const string ORIGIN = 'https://bedrock-runtime.us-east-1.amazonaws.com';

    /** @var list<array{method: string, url: string, headers: array<string, list<string>>, body: string}> */
    public array $requests = [];

    /** @var list<MockResponse> */
    private array $responses;

    public function __construct(MockResponse ...$responses)
    {
        $this->responses = array_values($responses);
    }

    /** @param array<array-key, mixed> $body */
    public static function json(array $body, int $status = 200): MockResponse
    {
        return new MockResponse(json_encode($body, JSON_THROW_ON_ERROR), [
            'http_code' => $status,
            'response_headers' => ['Content-Type' => 'application/json'],
        ]);
    }

    public static function frames(string ...$frames): MockResponse
    {
        return new MockResponse($frames, [
            'response_headers' => ['Content-Type' => 'application/vnd.amazon.eventstream'],
        ]);
    }

    public function client(): SigV4SigningClient
    {
        return new SigV4SigningClient(
            self::ORIGIN,
            'us-east-1',
            'bedrock',
            new StaticCredentials(),
            transport: SignedTransport::answeredInProcess(function (string $method, string $url, array $options): MockResponse {
                $body = $options['body'] ?? '';

                if ($body instanceof Closure) {
                    $read = '';

                    while (($chunk = $body(16384)) !== '') {
                        $read .= $chunk;
                    }

                    $body = $read;
                }

                $this->requests[] = [
                    'method' => $method,
                    'url' => $url,
                    'headers' => $options['normalized_headers'] ?? [],
                    'body' => (string) $body,
                ];

                return array_shift($this->responses) ?? throw new \LogicException('No response left for ' . $url);
            }),
        );
    }

    /** @return array<array-key, mixed> */
    public function body(int $request = 0): array
    {
        return json_decode($this->requests[$request]['body'], true, flags: JSON_THROW_ON_ERROR);
    }

    public function header(string $name, int $request = 0): string
    {
        $line = $this->requests[$request]['headers'][strtolower($name)][0] ?? '';

        return substr($line, \strlen($name) + 2);
    }
}
