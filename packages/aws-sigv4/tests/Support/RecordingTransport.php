<?php

declare(strict_types=1);

namespace Kinetis\AwsSigV4\Tests\Support;

use Closure;
use Kinetis\AwsSigV4\SignedTransport;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * A responder that records every call it receives and answers each one
 * from a fixed script, the last entry repeating once the script runs
 * out. `SigV4SigningClient` owns the PSR-18 boundary, so a test observes
 * what was sent — headers, body, and the `max_redirects` option the
 * transport fixes — by observing the function underneath it.
 *
 * A request body is materialized as the call is recorded: Symfony hands
 * a large one over as a chunk-producing closure, and the responder is
 * called before that closure is consumed.
 *
 * asMockClient() hands the same responder over as a plain Symfony
 * client, for the one test that shows what a retrying decorator does to
 * a request when it gets underneath one.
 *
 * @phpstan-type ResponseSpec array{status?: int, headers?: array<string, string>, body?: string}
 * @phpstan-type RecordedCall array{method: string, url: string, body: string, options: array<string, mixed>}
 */
final class RecordingTransport
{
    /**
     * @var list<RecordedCall>
     */
    public array $calls = [];

    /**
     * @var Closure(string, string, array<string, mixed>): ResponseInterface
     */
    private readonly Closure $responder;

    /**
     * @param list<ResponseSpec> $script
     */
    public function __construct(array $script = [])
    {
        $this->responder = function (string $method, string $url, array $options) use ($script): ResponseInterface {
            $this->calls[] = [
                'method' => $method,
                'url' => $url,
                'body' => self::materialize($options['body'] ?? ''),
                'options' => $options,
            ];

            $spec = $script[min(count($this->calls) - 1, count($script) - 1)] ?? [];

            return new MockResponse($spec['body'] ?? '', [
                'http_code' => $spec['status'] ?? 200,
                'response_headers' => $spec['headers'] ?? [],
            ]);
        };
    }

    public function asTransport(): SignedTransport
    {
        return SignedTransport::answeredInProcess($this->responder);
    }

    public function asMockClient(): MockHttpClient
    {
        return new MockHttpClient($this->responder);
    }

    public function callCount(): int
    {
        return count($this->calls);
    }

    public function urlOfCall(int $index): string
    {
        return $this->calls[$index]['url'];
    }

    public function optionOfCall(int $index, string $name): mixed
    {
        return $this->calls[$index]['options'][$name] ?? null;
    }

    /**
     * Symfony hands headers to a transport as "Name: value" lines, so
     * they are parsed back into a name => values map here; a repeated
     * header keeps every value, in order.
     *
     * @return array<string, list<string>>
     */
    public function headersOfCall(int $index): array
    {
        $headers = [];

        /** @var list<string> $lines */
        $lines = $this->calls[$index]['options']['headers'] ?? [];

        foreach ($lines as $line) {
            [$name, $value] = explode(':', $line, 2);
            $headers[strtolower($name)][] = ltrim($value);
        }

        return $headers;
    }

    public function headerLineOfCall(int $index, string $name): string
    {
        return implode(', ', $this->headersOfCall($index)[strtolower($name)] ?? []);
    }

    public function bodyOfCall(int $index): string
    {
        return $this->calls[$index]['body'];
    }

    private static function materialize(mixed $body): string
    {
        if (\is_string($body)) {
            return $body;
        }

        if (!$body instanceof Closure) {
            return '';
        }

        $contents = '';

        while (($chunk = $body(8192)) !== '') {
            $contents .= (string) $chunk;
        }

        return $contents;
    }
}
