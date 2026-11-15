<?php

declare(strict_types=1);

namespace Kinetis\Neuron;

use Generator;
use Kinetis\Http\Responses\PlainTextResponse;
use Kinetis\Http\StreamedResponse;
use NeuronAI\Agent\Adapters\AGUIAdapter;
use NeuronAI\Agent\Adapters\VercelAIAdapter;
use NeuronAI\Workflow\Streaming\ProtocolEvent;
use NeuronAI\Workflow\Streaming\SSEEncoder;
use Throwable;

/**
 * An agent run streamed to the browser in a Neuron UI protocol, one
 * Server-Sent Event per protocol event, each flushed as it is written.
 *
 * Built for one request, with the adapter the run streams through.
 *
 * {@link https://kinetis.dev/docs/neuron.html} states the contract.
 */
final readonly class AgentStream
{
    public function __construct(private VercelAIAdapter|AGUIAdapter $adapter) {}

    /**
     * $events is the generator `Agent::stream()` returns for a run whose
     * stream adapter is the one given here. It is consumed when the
     * response is emitted, after the status and the adapter's headers.
     *
     * @param Generator<int, ProtocolEvent, mixed, mixed> $events
     */
    public function response(Generator $events): StreamedResponse
    {
        $adapter = $this->adapter;
        $inner = PlainTextResponse::create('');

        foreach ($adapter->getHeaders() as $name => $value) {
            $inner = $inner->withHeader($name, $value);
        }

        return new StreamedResponse($inner, static function () use ($events, $adapter): void {
            try {
                foreach (SSEEncoder::encode($events) as $frame) {
                    self::write($frame);
                }
            } catch (Throwable $failure) {
                // The client has gone: no one reads an error frame, and
                // the stop its departure caused is not a server failure.
                if (connection_aborted() === 1) {
                    return;
                }

                // Empty when the run already sent its error frames.
                foreach ($adapter->error($failure) as $event) {
                    self::write(SSEEncoder::frame($event));
                }

                throw $failure;
            }
        });
    }

    private static function write(string $frame): void
    {
        echo $frame;

        if (ob_get_level() > 0) {
            ob_flush();
        }

        flush();
    }
}
