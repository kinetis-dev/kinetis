<?php

declare(strict_types=1);

namespace Kinetis\Neuron\Bedrock;

use JsonException;
use Kinetis\AwsSigV4\SigV4SigningClient;
use NeuronAI\Exceptions\ProviderException;
use Nyholm\Psr7\Request;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use RuntimeException;

/**
 * One signed `POST /model/{modelId}/{action}` to the Bedrock Runtime REST
 * API, sent once through the signing client's origin, and the bounded read
 * of what it answers.
 *
 * @internal
 */
final readonly class BedrockApi
{
    private const int READ_BYTES = 8192;

    public function __construct(
        private SigV4SigningClient $client,
        private int $maxBytes,
    ) {}

    /**
     * A 2xx response, whose body the caller reads and closes. Any other
     * status is read, closed and thrown.
     *
     * @throws ProviderException
     */
    public function post(string $model, string $action, string $body, string $accept): ResponseInterface
    {
        $request = new Request(
            'POST',
            '/model/' . rawurlencode($model) . '/' . $action,
            ['Content-Type' => 'application/json', 'Accept' => $accept],
            $body,
        );

        try {
            $response = $this->client->sendRequest($request);
        } catch (ClientExceptionInterface $e) {
            throw new ProviderException('The Bedrock request failed: ' . $e->getMessage(), previous: $e);
        }

        $status = $response->getStatusCode();

        if ($status >= 200 && $status < 300) {
            return $response;
        }

        try {
            $error = json_decode($this->read($response->getBody()), true);
        } catch (ProviderException) {
            $error = null;
        }

        $message = \is_array($error) ? ($error['message'] ?? $error['Message'] ?? null) : null;

        throw new ProviderException(\is_string($message) && $message !== ''
            ? "Bedrock answered HTTP {$status}: {$message}"
            : "Bedrock answered HTTP {$status}.");
    }

    /**
     * @return array<array-key, mixed>
     *
     * @throws ProviderException
     */
    public function json(ResponseInterface $response): array
    {
        $body = $this->read($response->getBody());

        try {
            $decoded = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new ProviderException('Bedrock answered with a body that is not JSON.', previous: $e);
        }

        if (!\is_array($decoded)) {
            throw new ProviderException('Bedrock answered with a body that is not a JSON object.');
        }

        return $decoded;
    }

    public function decoder(StreamInterface $body): EventStreamDecoder
    {
        return new EventStreamDecoder($body, $this->maxBytes);
    }

    /**
     * The whole body, every byte counted against the ceiling. The body is
     * closed whether or not it was read to the end.
     *
     * @throws ProviderException
     */
    private function read(StreamInterface $body): string
    {
        $contents = '';

        try {
            while (!$body->eof()) {
                $contents .= $body->read(self::READ_BYTES);

                if (\strlen($contents) > $this->maxBytes) {
                    throw new ProviderException(\sprintf(EventStreamDecoder::TOO_LARGE, $this->maxBytes));
                }
            }
        } catch (RuntimeException $e) {
            throw new ProviderException('The Bedrock response could not be read.', previous: $e);
        } finally {
            $body->close();
        }

        return $contents;
    }
}
