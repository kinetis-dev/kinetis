<?php

declare(strict_types=1);

namespace Kinetis\Neuron;

use Generator;
use Kinetis\RevoltHttpClient\Exception\HttpRequestException;
use Kinetis\RevoltHttpClient\HttpResponse;
use NeuronAI\Exceptions\HttpException;
use NeuronAI\HttpClient\HttpRequest;
use NeuronAI\HttpClient\StreamInterface;

/**
 * A response body read lazily from {@see HttpResponse::chunks()}, under
 * the operation's deadline and byte ceiling. Only the bytes a caller has
 * not yet taken from the current piece are held here.
 *
 * @internal {@see NeuronHttpClient::stream()} builds every instance.
 */
final class ResponseStream implements StreamInterface
{
    private string $buffer = '';

    private bool $started = false;

    private bool $ended = false;

    /**
     * @param Generator<int, string> $chunks
     */
    public function __construct(
        private readonly HttpResponse $response,
        private ?Generator $chunks,
        private readonly HttpRequest $request,
    ) {}

    #[\Override]
    public function eof(): bool
    {
        return $this->ended && $this->buffer === '';
    }

    #[\Override]
    public function read(int $length): string
    {
        if ($this->buffer === '') {
            $this->buffer = $this->pull() ?? '';
        }

        $data = substr($this->buffer, 0, max(0, $length));
        $this->buffer = substr($this->buffer, strlen($data));

        return $data;
    }

    #[\Override]
    public function readLine(): string
    {
        while (($end = strpos($this->buffer, "\n")) === false) {
            $piece = $this->pull();

            if ($piece === null) {
                $line = $this->buffer;
                $this->buffer = '';

                return $line;
            }

            $this->buffer .= $piece;
        }

        $line = substr($this->buffer, 0, $end + 1);
        $this->buffer = substr($this->buffer, $end + 1);

        return $line;
    }

    /** Cancels the transport response if the body has not been read to its end. */
    #[\Override]
    public function close(): void
    {
        $this->ended = true;
        $this->buffer = '';
        $this->chunks = null;
        $this->response->discard();
    }

    /**
     * The next piece of the body, or null once it has ended. A failure
     * mid-body is reported, never taken for the end of it.
     */
    private function pull(): ?string
    {
        if ($this->ended || $this->chunks === null) {
            return null;
        }

        try {
            if ($this->started) {
                $this->chunks->next();
            }

            $this->started = true;

            if ($this->chunks->valid()) {
                return $this->chunks->current();
            }
        } catch (HttpRequestException $failure) {
            $this->close();

            throw HttpException::networkError($this->request, $failure->getMessage(), $failure);
        }

        $this->ended = true;
        $this->chunks = null;

        return null;
    }
}
