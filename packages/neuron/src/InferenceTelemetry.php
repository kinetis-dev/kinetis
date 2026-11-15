<?php

declare(strict_types=1);

namespace Kinetis\Neuron;

use Kinetis\Instrumentation\Telemetry;
use Kinetis\Instrumentation\TelemetryInterface;
use Kinetis\Neuron\Bedrock\BedrockProvider;
use NeuronAI\Agent\Agent;
use NeuronAI\Agent\Observability\InferenceStart;
use NeuronAI\Agent\Observability\InferenceStop;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Observability\ObservabilityEvent;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\Alibaba\DashScopeOpenAI;
use NeuronAI\Providers\Anthropic\Anthropic;
use NeuronAI\Providers\Anthropic\AnthropicVertex;
use NeuronAI\Providers\AWS\BedrockRuntime;
use NeuronAI\Providers\Cohere\Cohere;
use NeuronAI\Providers\Deepseek\Deepseek;
use NeuronAI\Providers\Gemini\Gemini;
use NeuronAI\Providers\Gemini\GeminiVertex;
use NeuronAI\Providers\HuggingFace\HuggingFace;
use NeuronAI\Providers\Mistral\Mistral;
use NeuronAI\Providers\Ollama\Ollama;
use NeuronAI\Providers\OpenAI\AzureOpenAI;
use NeuronAI\Providers\OpenAI\OpenAI;
use NeuronAI\Providers\OpenAI\Responses\OpenAIResponses;
use NeuronAI\Providers\XAI\Grok;
use NeuronAI\Providers\ZAI\ZAI;
use NeuronAI\Workflow\Observability\WorkflowError;
use WeakMap;
use WeakReference;

/**
 * Reports every provider call of one agent through the framework's
 * inference hooks, which kinetis/telemetry turns into spans. Tool calls
 * are not reported here: an `McpTools` tool already has its span.
 *
 * One instance per agent, owned by that agent's listener registry, so
 * it ends with the agent.
 *
 * {@link https://kinetis.dev/docs/neuron.html} states the contract.
 */
final class InferenceTelemetry
{
    /**
     * The token of each inference in flight, keyed by the message Neuron
     * reports both its start and its stop with. Wrapped, because a token
     * may be null.
     *
     * @var WeakMap<Message, array{mixed}>
     */
    private WeakMap $inFlight;

    /**
     * @param WeakReference<Agent> $agent weak, so the agent's registry
     *     holding this listener is no reference cycle
     */
    private function __construct(
        private readonly WeakReference $agent,
        private readonly TelemetryInterface $telemetry,
    ) {
        $this->inFlight = new WeakMap();
    }

    /**
     * Subscribes $agent's provider calls to the framework's telemetry and
     * returns it.
     *
     * @template T of Agent
     * @param T $agent
     * @return T
     */
    public static function observe(Agent $agent): Agent
    {
        $listener = new self(WeakReference::create($agent), Telemetry::global());

        return $agent->subscribe(ObservabilityEvent::class, $listener->handle(...));
    }

    private function handle(object $event): void
    {
        match (true) {
            $event instanceof InferenceStart => $this->started($event),
            $event instanceof InferenceStop => $this->stopped($event),
            $event instanceof WorkflowError => $this->failed($event),
            default => null,
        };
    }

    private function started(InferenceStart $event): void
    {
        $provider = $this->agent->get()?->getProvider();

        if ($provider === null) {
            return;
        }

        $this->inFlight[$event->message] = [
            $this->telemetry->inferenceStarted(self::system($provider), $provider->getModel()),
        ];
    }

    private function stopped(InferenceStop $event): void
    {
        if ($event->message === false) {
            return;
        }

        $entry = $this->inFlight[$event->message] ?? null;

        if ($entry === null) {
            return;
        }

        unset($this->inFlight[$event->message]);
        $usage = $event->response->message()->getUsage();
        $this->telemetry->inferenceEnded($entry[0], $usage?->inputTokens, $usage?->outputTokens, null);
    }

    /**
     * Neuron reports a WorkflowError, instead of an InferenceStop, for a
     * run that threw and for one whose consumer stopped reading it.
     */
    private function failed(WorkflowError $event): void
    {
        $inFlight = $this->inFlight;
        $this->inFlight = new WeakMap();

        foreach ($inFlight as [$token]) {
            $this->telemetry->inferenceEnded($token, null, null, $event->exception);
        }
    }

    /**
     * The provider in OpenTelemetry's `gen_ai.system` vocabulary, matched
     * on the exact class: a subclass, such as `OpenAILike`, may reach any
     * service.
     */
    private static function system(AIProviderInterface $provider): string
    {
        return match ($provider::class) {
            OpenAI::class, OpenAIResponses::class => 'openai',
            AzureOpenAI::class => 'az.ai.openai',
            Anthropic::class => 'anthropic',
            AnthropicVertex::class, GeminiVertex::class => 'gcp.vertex_ai',
            Gemini::class => 'gcp.gemini',
            BedrockRuntime::class, BedrockProvider::class => 'aws.bedrock',
            Cohere::class => 'cohere',
            Deepseek::class => 'deepseek',
            Mistral::class => 'mistral_ai',
            Grok::class => 'xai',
            DashScopeOpenAI::class => 'alibaba',
            HuggingFace::class => 'huggingface',
            Ollama::class => 'ollama',
            ZAI::class => 'zai',
            default => '_OTHER',
        };
    }
}
