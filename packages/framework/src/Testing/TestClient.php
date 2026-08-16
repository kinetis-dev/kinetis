<?php

declare(strict_types=1);

namespace Kinetis\Testing;

use Kinetis\Http\Kernel;
use Nyholm\Psr7\ServerRequest;

/**
 * Builds a PSR-7 request and dispatches it straight through a Kernel —
 * the same thing every Kernel test in this repo already does by hand
 * (construct a ServerRequest, call handle()), wrapped so a consumer
 * testing their own app doesn't have to.
 *
 * Every method returns a {@see TestResponse}, which is itself a PSR-7
 * response with assertions attached.
 */
final readonly class TestClient
{
    public function __construct(
        private Kernel $kernel,
    ) {}

    /**
     * @param array<string, mixed> $query
     * @param array<string, string> $headers
     */
    public function get(string $uri, array $query = [], array $headers = []): TestResponse
    {
        return $this->request('GET', $uri, query: $query, headers: $headers);
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, string> $headers
     */
    public function post(string $uri, array $body = [], array $headers = []): TestResponse
    {
        return $this->request('POST', $uri, body: $body, headers: $headers);
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, string> $headers
     */
    public function put(string $uri, array $body = [], array $headers = []): TestResponse
    {
        return $this->request('PUT', $uri, body: $body, headers: $headers);
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, string> $headers
     */
    public function patch(string $uri, array $body = [], array $headers = []): TestResponse
    {
        return $this->request('PATCH', $uri, body: $body, headers: $headers);
    }

    /**
     * @param array<string, string> $headers
     */
    public function delete(string $uri, array $headers = []): TestResponse
    {
        return $this->request('DELETE', $uri, headers: $headers);
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, string> $headers
     * @param array<string, mixed> $query
     */
    public function request(
        string $method,
        string $uri,
        array $body = [],
        array $headers = [],
        array $query = [],
    ): TestResponse {
        if ($body !== [] && !isset($headers['Content-Type'])) {
            $headers['Content-Type'] = 'application/json';
        }

        $request = new ServerRequest(
            method: $method,
            uri: $uri,
            headers: $headers,
            body: $body !== [] ? json_encode($body, JSON_THROW_ON_ERROR) : null,
        );

        if ($query !== []) {
            $request = $request->withQueryParams($query);
        }

        return new TestResponse($this->kernel->handle($request));
    }
}
