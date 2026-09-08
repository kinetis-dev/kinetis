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
 *
 * $lease is what the response holds for a body that has not been written
 * yet, and the whole of this class's part in the interface's settlement
 * contract: emitting releases it, {@see abandon()} releases it, and it is
 * released once whichever order those arrive in. {@see Kernel} passes the
 * lease on the request scope its emitter resolves from; a controller
 * building its own stream holds nothing and leaves it null. Every `with*`
 * clone carries the same lease, so a middleware editing a header or a
 * status hands on the response that still carries the scope rather than
 * one that has quietly dropped it.
 */
final class StreamedResponse implements ResponseInterface, StreamableResponseInterface
{
    public function __construct(
        private readonly ResponseInterface $inner,
        private readonly Closure $emitter,
        private readonly ?StreamScopeLease $lease = null,
    ) {}

    #[\Override]
    public function getEmitter(): Closure
    {
        $emitter = $this->emitter;
        $lease = $this->lease;

        if ($lease === null) {
            return $emitter;
        }

        return static function () use ($emitter, $lease): void {
            try {
                $emitter();
            } finally {
                // release() never throws, so an emitter failure stays
                // the one that propagates.
                $lease->release();
            }
        };
    }

    #[\Override]
    public function abandon(): void
    {
        $this->lease?->release();
    }

    /**
     * Whether $lease is the one this response settles — true for the
     * wrapper it was issued to and for every `with*` clone of that
     * wrapper, false for any other response. This is how Kernel tells a
     * clone of its own wrapper from a stream a middleware put in its
     * place.
     */
    public function carries(StreamScopeLease $lease): bool
    {
        return $this->lease === $lease;
    }

    #[\Override]
    public function getProtocolVersion(): string
    {
        return $this->inner->getProtocolVersion();
    }

    #[\Override]
    public function withProtocolVersion(string $version): static
    {
        return new self($this->inner->withProtocolVersion($version), $this->emitter, $this->lease);
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
        return new self($this->inner->withHeader($name, $value), $this->emitter, $this->lease);
    }

    #[\Override]
    public function withAddedHeader(string $name, $value): static
    {
        return new self($this->inner->withAddedHeader($name, $value), $this->emitter, $this->lease);
    }

    #[\Override]
    public function withoutHeader(string $name): static
    {
        return new self($this->inner->withoutHeader($name), $this->emitter, $this->lease);
    }

    #[\Override]
    public function getBody(): StreamInterface
    {
        return $this->inner->getBody();
    }

    #[\Override]
    public function withBody(StreamInterface $body): static
    {
        return new self($this->inner->withBody($body), $this->emitter, $this->lease);
    }

    #[\Override]
    public function getStatusCode(): int
    {
        return $this->inner->getStatusCode();
    }

    #[\Override]
    public function withStatus(int $code, string $reasonPhrase = ''): static
    {
        return new self($this->inner->withStatus($code, $reasonPhrase), $this->emitter, $this->lease);
    }

    #[\Override]
    public function getReasonPhrase(): string
    {
        return $this->inner->getReasonPhrase();
    }
}
