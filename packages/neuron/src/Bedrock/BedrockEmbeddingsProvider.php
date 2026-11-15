<?php

declare(strict_types=1);

namespace Kinetis\Neuron\Bedrock;

use JsonException;
use Kinetis\AwsSigV4\SigV4SigningClient;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\RAG\Embeddings\AbstractEmbeddingsProvider;

/**
 * Amazon Titan text embeddings through Bedrock's InvokeModel API, sent
 * through a {@see SigV4SigningClient} instead of the AWS SDK. The request
 * body is the `inputText` one Neuron's `AwsBedrockEmbeddingsProvider`
 * sends, and the vector is the answer's `embedding`.
 *
 * Every response byte counts against $maxResponseBytes. The deadline is
 * the client's transport's.
 */
final class BedrockEmbeddingsProvider extends AbstractEmbeddingsProvider
{
    private readonly BedrockApi $api;

    public function __construct(
        SigV4SigningClient $client,
        private readonly string $model = 'amazon.titan-embed-text-v2:0',
        int $maxResponseBytes = 1024 * 1024,
    ) {
        $this->api = new BedrockApi($client, $maxResponseBytes);
    }

    /**
     * @return array<int, float>
     *
     * @throws ProviderException
     */
    #[\Override]
    public function embedText(string $text): array
    {
        try {
            $body = json_encode(['inputText' => $text], JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new ProviderException('The Bedrock request could not be encoded as JSON.', previous: $e);
        }

        $answer = $this->api->json($this->api->post($this->model, 'invoke', $body, 'application/json'));

        if (!\is_array($answer['embedding'] ?? null)) {
            throw new ProviderException('Bedrock answered without an embedding.');
        }

        return $answer['embedding'];
    }
}
