<?php

declare(strict_types=1);

/**
 * A stand-in Bedrock Runtime for the provider tests, served by `php -S`.
 *
 * `POST /model/slow/converse` answers a Converse response with the text
 * `pong` after one second. `POST /model/split/converse-stream` sends
 * ConverseStream frames in five writes 100 ms apart, the first ending
 * inside a frame's headers and the second inside a frame's payload.
 * `POST /model/until-abort/converse-stream` sends a text frame, and
 * `/model/until-abort/converse` 4 KiB of a JSON body, every 50 ms for up to
 * five seconds, and records in the file named by the first user message
 * whether the client went away.
 */

require __DIR__ . '/Bedrock/EventStreamFrame.php';

use Kinetis\Neuron\Tests\Fixtures\Bedrock\EventStreamFrame;

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$request = json_decode((string) file_get_contents('php://input'), true);

$send = static function (string $bytes): void {
    echo $bytes;
    flush();
};

if ($path === '/model/slow/converse') {
    sleep(1);
    header('Content-Type: application/json');
    echo json_encode([
        'output' => ['message' => ['role' => 'assistant', 'content' => [['text' => 'pong']]]],
        'stopReason' => 'end_turn',
        'usage' => ['inputTokens' => 3, 'outputTokens' => 1],
    ]);

    return;
}

if ($path === '/model/split/converse-stream') {
    header('Content-Type: application/vnd.amazon.eventstream');
    $frames = EventStreamFrame::event('messageStart', ['role' => 'assistant'])
        . EventStreamFrame::event('contentBlockDelta', ['contentBlockIndex' => 0, 'delta' => ['text' => 'Hel']])
        . EventStreamFrame::event('contentBlockDelta', ['contentBlockIndex' => 0, 'delta' => ['text' => 'lo']]);
    $middle = \strlen($frames) - 10;
    $tail = EventStreamFrame::event('contentBlockStop', ['contentBlockIndex' => 0])
        . EventStreamFrame::event('messageStop', ['stopReason' => 'end_turn']);

    foreach ([substr($frames, 0, 20), substr($frames, 20, $middle - 20), substr($frames, $middle), $tail] as $piece) {
        $send($piece);
        usleep(100_000);
    }

    $send(EventStreamFrame::event('metadata', ['usage' => ['inputTokens' => 4, 'outputTokens' => 2]]));

    return;
}

if ($path === '/model/until-abort/converse-stream' || $path === '/model/until-abort/converse') {
    $mark = $request['messages'][0]['content'][0]['text'] ?? null;
    $streams = str_ends_with($path, '-stream');
    ignore_user_abort(true);
    header('Content-Type: ' . ($streams ? 'application/vnd.amazon.eventstream' : 'application/json'));

    for ($i = 0; $i < 100; ++$i) {
        $send($streams
            ? EventStreamFrame::event('contentBlockDelta', ['contentBlockIndex' => 0, 'delta' => ['text' => "w{$i} "]])
            : str_repeat(' ', 4096));

        if (connection_aborted() === 1) {
            file_put_contents((string) $mark, "aborted after {$i} writes\n", FILE_APPEND);

            return;
        }

        usleep(50_000);
    }

    file_put_contents((string) $mark, "completed\n", FILE_APPEND);

    return;
}

http_response_code(404);
header('Content-Type: application/json');
echo json_encode(['message' => "No route for {$path}"]);
