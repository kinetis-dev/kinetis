<?php

declare(strict_types=1);

namespace Kinetis\Neuron\Tests\Bedrock;

use Kinetis\Neuron\Bedrock\BedrockEmbeddingsProvider;
use Kinetis\Neuron\Tests\Fixtures\Bedrock\RecordingBedrock;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\RAG\Document;
use PHPUnit\Framework\TestCase;

final class BedrockEmbeddingsProviderTest extends TestCase
{
    public function test_text_is_sent_as_titan_input_and_the_embedding_is_read_back(): void
    {
        $bedrock = new RecordingBedrock(RecordingBedrock::json(['embedding' => [0.25, -0.5, 0.75], 'inputTextTokenCount' => 2]));

        $embedding = new BedrockEmbeddingsProvider($bedrock->client())->embedText('Kinetis workers');

        self::assertSame([0.25, -0.5, 0.75], $embedding);
        self::assertSame(['inputText' => 'Kinetis workers'], $bedrock->body());
        self::assertSame(
            RecordingBedrock::ORIGIN . '/model/amazon.titan-embed-text-v2%3A0/invoke',
            $bedrock->requests[0]['url'],
        );
        self::assertSame('application/json', $bedrock->header('Content-Type'));
        self::assertSame('application/json', $bedrock->header('Accept'));
    }

    public function test_documents_are_embedded_one_request_each(): void
    {
        $bedrock = new RecordingBedrock(
            RecordingBedrock::json(['embedding' => [1.5, 0.5]]),
            RecordingBedrock::json(['embedding' => [0.5, 1.5]]),
        );

        $documents = new BedrockEmbeddingsProvider($bedrock->client(), 'amazon.titan-embed-text-v1')
            ->embedDocuments([new Document('first'), new Document('second')]);

        self::assertSame([[1.5, 0.5], [0.5, 1.5]], array_map(static fn (Document $d): ?array => $d->getEmbedding(), $documents));
        self::assertSame(['inputText' => 'second'], $bedrock->body(1));
        self::assertStringEndsWith('/model/amazon.titan-embed-text-v1/invoke', $bedrock->requests[1]['url']);
    }

    public function test_an_answer_without_an_embedding_is_refused(): void
    {
        $bedrock = new RecordingBedrock(RecordingBedrock::json(['inputTextTokenCount' => 2]));

        $this->expectExceptionObject(new ProviderException('Bedrock answered without an embedding.'));

        new BedrockEmbeddingsProvider($bedrock->client())->embedText('text');
    }
}
