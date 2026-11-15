<?php

declare(strict_types=1);

// The front controller BrowserDisconnectTest streams an agent from,
// under a FrankenPHP worker or PHP-FPM behind nginx. Named index.php
// because FrankenPHP's php-server routes a worker through the document
// root's index.php. RuntimeDetector picks the adapter as an application
// does, and the handler answers the way docs/neuron.md shows: a
// request-scoped provider whose client stops when the browser leaves,
// streamed through AgentStream.
//
// GET /?mark={file}, with {file} under KINETIS_NEURON_STATE_DIR, runs
// one answer from the stand-in provider at KINETIS_NEURON_PROVIDER,
// which records in {file} whether its client left. Any other request
// answers 204, which is how a driver knows the stack is up. The handler
// appends to {file}.server what the emitter saw once it returned; under
// PHP-FPM, which ends the script at the first write after the client
// left, it never returns.

require __DIR__ . '/../../../vendor/autoload.php';

use Kinetis\Http\Responses\PlainTextResponse;
use Kinetis\Http\StreamedResponse;
use Kinetis\Http\TrustedProxies;
use Kinetis\Neuron\AgentStream;
use Kinetis\Neuron\NeuronHttpClient;
use Kinetis\RevoltHttpClient\Http;
use Kinetis\Runtime\RuntimeDetector;
use NeuronAI\Agent\Adapters\VercelAIAdapter;
use NeuronAI\Agent\Agent;
use NeuronAI\Agent\AgentState;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\HttpClient\StoppableHttpClient;
use NeuronAI\Providers\OpenAILike;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

$base = 'http://' . getenv('KINETIS_NEURON_PROVIDER') . '/v1';
$stateDir = getenv('KINETIS_NEURON_STATE_DIR') . '/';
$client = new NeuronHttpClient(new Http()->withTimeout(30), $base);

RuntimeDetector::detect(TrustedProxies::fromList([]))->run(
    static function (ServerRequestInterface $request) use ($client, $base, $stateDir): ResponseInterface {
        $mark = $request->getQueryParams()['mark'] ?? null;

        if (!is_string($mark) || !str_starts_with($mark, $stateDir)) {
            return PlainTextResponse::create('', 204);
        }

        $adapter = new VercelAIAdapter();
        $run = Agent::make()
            ->setAiProvider(new OpenAILike(
                $base,
                'test-key',
                'until-abort',
                ['mark' => $mark],
                httpClient: new StoppableHttpClient($client, static fn (): bool => connection_aborted() === 1),
            ))
            ->setThreadId('thread-1')
            ->setStreamAdapter(static fn (): VercelAIAdapter => $adapter)
            ->stream(new UserMessage('ping'));

        $response = new AgentStream($adapter)->response($run);

        return new StreamedResponse($response, static function () use ($response, $run, $mark): void {
            ($response->getEmitter())();

            $state = $run->getReturn();
            $answer = $state instanceof AgentState ? $state->getMessage() : null;

            file_put_contents($mark . '.server', sprintf(
                "emitter returned aborted=%d stop=%s\n",
                connection_aborted(),
                $answer instanceof AssistantMessage ? $answer->stopReason() : 'none',
            ), FILE_APPEND);
        });
    },
);
