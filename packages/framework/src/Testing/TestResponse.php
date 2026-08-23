<?php

declare(strict_types=1);

namespace Kinetis\Testing;

use PHPUnit\Framework\Assert;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

/**
 * What {@see TestClient} hands back: the real PSR-7 response, wrapped so
 * a test can assert against it without decoding the body by hand.
 *
 * Implements ResponseInterface itself and delegates every method, so
 * anything already reading `getStatusCode()`/`getBody()` off the client's
 * return value keeps working, and a response can still be passed to code
 * that expects plain PSR-7.
 *
 * Assertions read left to right and return $this, so a test states the
 * whole expected shape in one expression:
 *
 *     $client->post('/orders', ['sku' => 'A1'])
 *         ->assertStatus(201)
 *         ->assertJsonPath('order.sku', 'A1');
 *
 * Failures report the response's own status and body rather than only
 * the mismatched value — when a route 500s, the useful information is
 * the error body, not "expected 201, got 500".
 */
final class TestResponse implements ResponseInterface
{
    public function __construct(private readonly ResponseInterface $response) {}

    public function assertStatus(int $expected): self
    {
        Assert::assertSame(
            $expected,
            $this->response->getStatusCode(),
            "Expected HTTP {$expected}, got {$this->response->getStatusCode()}." . $this->bodyForFailure(),
        );

        return $this;
    }

    public function assertOk(): self
    {
        return $this->assertStatus(200);
    }

    public function assertCreated(): self
    {
        return $this->assertStatus(201);
    }

    public function assertNotFound(): self
    {
        return $this->assertStatus(404);
    }

    /**
     * Passes for any 2xx, for a test that cares the request succeeded
     * without pinning which success status a route chose.
     */
    public function assertSuccessful(): self
    {
        $status = $this->response->getStatusCode();

        Assert::assertTrue(
            $status >= 200 && $status < 300,
            "Expected a 2xx status, got {$status}." . $this->bodyForFailure(),
        );

        return $this;
    }

    public function assertHeader(string $name, ?string $expected = null): self
    {
        Assert::assertTrue(
            $this->response->hasHeader($name),
            "Expected header \"{$name}\" to be present." . $this->bodyForFailure(),
        );

        if ($expected !== null) {
            Assert::assertSame($expected, $this->response->getHeaderLine($name), "Header \"{$name}\" mismatch.");
        }

        return $this;
    }

    /**
     * The whole decoded body, compared exactly — key order included, since
     * a JSON API's response shape is part of its contract.
     *
     * @param array<array-key, mixed> $expected
     */
    public function assertJson(array $expected): self
    {
        Assert::assertSame($expected, $this->json(), 'Response body does not match.' . $this->bodyForFailure());

        return $this;
    }

    /**
     * One value at a dot-delimited path — `order.items.0.sku` — so a test
     * can pin what it cares about without restating the rest of the
     * document.
     */
    public function assertJsonPath(string $path, mixed $expected): self
    {
        Assert::assertSame(
            $expected,
            $this->jsonPath($path),
            "Value at JSON path \"{$path}\" does not match." . $this->bodyForFailure(),
        );

        return $this;
    }

    public function assertJsonPathMissing(string $path): self
    {
        Assert::assertNull(
            $this->jsonPath($path),
            "Expected no value at JSON path \"{$path}\"." . $this->bodyForFailure(),
        );

        return $this;
    }

    /**
     * A validation failure names every field that failed at once (see
     * Kinetis\Validation\Hydrator), so this asserts the 422 and the field
     * together — the pair a test actually cares about.
     */
    public function assertValidationError(string $field): self
    {
        $this->assertStatus(422);

        $errors = $this->jsonPath('errors');

        Assert::assertIsArray($errors, 'Expected an "errors" object in the response.' . $this->bodyForFailure());
        Assert::assertArrayHasKey(
            $field,
            $errors,
            "Expected a validation error for \"{$field}\"." . $this->bodyForFailure(),
        );

        return $this;
    }

    public function assertBodyContains(string $needle): self
    {
        Assert::assertStringContainsString($needle, $this->body(), 'Response body does not contain the expected text.');

        return $this;
    }

    /**
     * The decoded JSON body. Reads the stream from the start every time,
     * so calling it after something else has already consumed the body
     * still works.
     */
    public function json(): mixed
    {
        return json_decode($this->body(), associative: true);
    }

    public function body(): string
    {
        $stream = $this->response->getBody();

        if ($stream->isSeekable()) {
            $stream->rewind();
        }

        return $stream->getContents();
    }

    private function jsonPath(string $path): mixed
    {
        $value = $this->json();

        foreach (explode('.', $path) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return null;
            }

            $value = $value[$segment];
        }

        return $value;
    }

    private function bodyForFailure(): string
    {
        $body = $this->body();

        return $body === '' ? '' : "\nResponse body: {$body}";
    }

    // --- ResponseInterface passthrough ---------------------------------

    #[\Override]
    public function getStatusCode(): int
    {
        return $this->response->getStatusCode();
    }

    #[\Override]
    public function withStatus(int $code, string $reasonPhrase = ''): static
    {
        return new self($this->response->withStatus($code, $reasonPhrase));
    }

    #[\Override]
    public function getReasonPhrase(): string
    {
        return $this->response->getReasonPhrase();
    }

    #[\Override]
    public function getProtocolVersion(): string
    {
        return $this->response->getProtocolVersion();
    }

    #[\Override]
    public function withProtocolVersion(string $version): static
    {
        return new self($this->response->withProtocolVersion($version));
    }

    #[\Override]
    public function getHeaders(): array
    {
        return $this->response->getHeaders();
    }

    #[\Override]
    public function hasHeader(string $name): bool
    {
        return $this->response->hasHeader($name);
    }

    #[\Override]
    public function getHeader(string $name): array
    {
        return $this->response->getHeader($name);
    }

    #[\Override]
    public function getHeaderLine(string $name): string
    {
        return $this->response->getHeaderLine($name);
    }

    #[\Override]
    public function withHeader(string $name, mixed $value): static
    {
        return new self($this->response->withHeader($name, $value));
    }

    #[\Override]
    public function withAddedHeader(string $name, mixed $value): static
    {
        return new self($this->response->withAddedHeader($name, $value));
    }

    #[\Override]
    public function withoutHeader(string $name): static
    {
        return new self($this->response->withoutHeader($name));
    }

    #[\Override]
    public function getBody(): StreamInterface
    {
        return $this->response->getBody();
    }

    #[\Override]
    public function withBody(StreamInterface $body): static
    {
        return new self($this->response->withBody($body));
    }
}
