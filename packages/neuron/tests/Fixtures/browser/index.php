<?php

declare(strict_types=1);

// The front controller BrowserDisconnectTest streams an agent from,
// under a FrankenPHP worker or PHP-FPM behind nginx. Named index.php
// because FrankenPHP's php-server routes a worker through the document
// root's index.php. RuntimeDetector picks the adapter as an application
// does, and the handler answers the way docs/neuron.md shows: a
// request-scoped provider whose client stops when the adapter's client
// connection reports the browser gone, streamed through AgentStream.
//
// POST / with the JSON body {"mark": "{file}"}, {file} under
// KINETIS_NEURON_STATE_DIR, runs one answer from the stand-in provider
// at KINETIS_NEURON_PROVIDER, which records in {file} whether its client
// left. The handler appends to {file}.server what the emitter saw once it
// returned; under PHP-FPM, which ends the script at the first write after
// the client left, it never returns.
//
// GET /?probe={file} writes and flushes one line, then appends to {file}
// what the client connection reports for that request. Any other request
// answers 204, which is how a driver knows the stack is up.

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
$runtime = RuntimeDetector::detect(TrustedProxies::fromList([]));
$connection = $runtime->clientConnection();

$inStateDir = static fn (mixed $file): bool => is_string($file) && str_starts_with($file, $stateDir);

$probe = static function (string $file) use ($connection): ResponseInterface {
    return new StreamedResponse(PlainTextResponse::create(''), static function () use ($file, $connection): void {
        echo "ok\n";
        flush();

        file_put_contents($file, sprintf("gone=%d\n", $connection->isGone()), FILE_APPEND);
    });
};

$answer = static function (string $mark) use ($client, $base, $connection): ResponseInterface {
    $adapter = new VercelAIAdapter();
    $run = Agent::make()
        ->setAiProvider(new OpenAILike(
            $base,
            'test-key',
            'until-abort',
            ['mark' => $mark],
            httpClient: new StoppableHttpClient($client, $connection->isGone(...)),
        ))
        ->setThreadId('thread-1')
        ->setStreamAdapter(static fn (): VercelAIAdapter => $adapter)
        ->stream(new UserMessage('ping'));

    $response = new AgentStream($adapter, $connection)->response($run);

    return new StreamedResponse($response, static function () use ($response, $run, $mark, $connection): void {
        ($response->getEmitter())();

        $state = $run->getReturn();
        $answer = $state instanceof AgentState ? $state->getMessage() : null;

        file_put_contents($mark . '.server', sprintf(
            "emitter returned gone=%d stop=%s\n",
            $connection->isGone(),
            $answer instanceof AssistantMessage ? $answer->stopReason() : 'none',
        ), FILE_APPEND);
    });
};

$runtime->run(static function (ServerRequestInterface $request) use ($inStateDir, $probe, $answer): ResponseInterface {
    if ($request->getMethod() === 'POST') {
        $body = json_decode((string) $request->getBody(), true);
        $mark = is_array($body) ? $body['mark'] ?? null : null;

        if ($inStateDir($mark)) {
            return $answer($mark);
        }
    }

    $file = $request->getQueryParams()['probe'] ?? null;

    if ($inStateDir($file)) {
        return $probe($file);
    }

    return PlainTextResponse::create('', 204);
});
