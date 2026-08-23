<?php

declare(strict_types=1);

namespace Kinetis\Http;

use Kinetis\Runtime\StreamableResponseInterface;
use Closure;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

/**
 * A PSR-7 response whose body is never read via getBody()/getContents() —
 * instead getEmitter() returns a closure that writes+flushes output
 * directly and incrementally when invoked. Composes a plain (empty-body)
 * ResponseInterface for status/headers rather than extending one, so this
 * stays a small, self-contained addition rather than depending on another
 * package's internals. Implements Kinetis\Runtime\StreamableResponseInterface
 * so SuperglobalsBridge/adapters can recognize it without Runtime needing to
 * know this concrete Http-layer class exists.
 */
final class StreamedResponse implements ResponseInterface, StreamableResponseInterface
{
    public function __construct(
        private readonly ResponseInterface $inner,
        private readonly Closure $emitter,
    ) {}

    #[\Override]
    public function getEmitter(): Closure
    {
        return $this->emitter;
    }

    #[\Override]
    public function getProtocolVersion(): string
    {
        return $this->inner->getProtocolVersion();
    }

    #[\Override]
    public function withProtocolVersion(string $version): static
    {
        return new self($this->inner->withProtocolVersion($version), $this->emitter);
    }

    #[\Override]
    public function getHeaders(): array
    {
        return $this->inner->getHeaders();
    }

    #[\Override]
    public function hasHeader(string $name): bool
    {
        return $this->inner->hasHeader($name);
    }

    #[\Override]
    public function getHeader(string $name): array
    {
        return $this->inner->getHeader($name);
    }

    #[\Override]
    public function getHeaderLine(string $name): string
    {
        return $this->inner->getHeaderLine($name);
    }

    #[\Override]
    public function withHeader(string $name, $value): static
    {
        return new self($this->inner->withHeader($name, $value), $this->emitter);
    }

    #[\Override]
    public function withAddedHeader(string $name, $value): static
    {
        return new self($this->inner->withAddedHeader($name, $value), $this->emitter);
    }

    #[\Override]
    public function withoutHeader(string $name): static
    {
        return new self($this->inner->withoutHeader($name), $this->emitter);
    }

    #[\Override]
    public function getBody(): StreamInterface
    {
        return $this->inner->getBody();
    }

    #[\Override]
    public function withBody(StreamInterface $body): static
    {
        return new self($this->inner->withBody($body), $this->emitter);
    }

    #[\Override]
    public function getStatusCode(): int
    {
        return $this->inner->getStatusCode();
    }

    #[\Override]
    public function withStatus(int $code, string $reasonPhrase = ''): static
    {
        return new self($this->inner->withStatus($code, $reasonPhrase), $this->emitter);
    }

    #[\Override]
    public function getReasonPhrase(): string
    {
        return $this->inner->getReasonPhrase();
    }
}
