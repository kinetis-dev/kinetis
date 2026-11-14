<?php

declare(strict_types=1);

/**
 * A stand-in provider for the transport tests, served by `php -S`.
 *
 * `POST /v1/chat/completions` answers as an OpenAI-compatible API: after
 * one second with a JSON completion, or, for `"stream": true`, with SSE
 * frames 200 ms apart. A streamed request for the model `cut` gets one
 * text frame and no finish. One for the model `until-abort` with a
 * `mark` member gets a text frame every 50 ms for up to five seconds,
 * and records in that file whether the client went away. `/v1/lines` sends `data: one\n`, then `data: tw`
 * and `o\n` as two pieces, then `last` with no newline, 300 ms apart.
 * `/v1/until-abort?mark=` sends a line every 50 ms for up to five
 * seconds and records whether the client went away. `/v1/stall` sends
 * one line, then nothing for three seconds. `/v1/bytes/{n}` sends n
 * bytes with no declared length.
 * `/v1/status/{code}` answers that status with a fixed body. `/v1/echo`
 * answers with what arrived. `/v1/slow/{ms}` answers after {ms}.
 * `/record?mark=` answers with a fixed body. With `?mark=`, every
 * endpoint appends its method and path to the mark file.
 */

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$mark = is_string($_GET['mark'] ?? null) ? $_GET['mark'] : null;
$body = (string) file_get_contents('php://input');

if ($mark !== null) {
    file_put_contents($mark, "{$_SERVER['REQUEST_METHOD']} {$path}\n", FILE_APPEND);
}

if ($path === '/v1/chat/completions') {
    $payload = json_decode($body, true);

    if (is_array($payload) && ($payload['stream'] ?? false) === true) {
        header('Content-Type: text/event-stream');
        $delta = static fn (string $content): string => 'data: '
            . json_encode(['choices' => [['index' => 0, 'delta' => ['content' => $content]]]]) . "\n\n";

        if (($payload['model'] ?? null) === 'until-abort' && is_string($payload['mark'] ?? null)) {
            ignore_user_abort(true);

            for ($i = 0; $i < 100; ++$i) {
                echo $delta("w{$i} ");
                flush();

                if (connection_aborted() === 1) {
                    file_put_contents($payload['mark'], "aborted after {$i} events\n", FILE_APPEND);

                    return;
                }

                usleep(50_000);
            }

            file_put_contents($payload['mark'], "completed\n", FILE_APPEND);
            echo 'data: ' . json_encode(['choices' => [['index' => 0, 'delta' => [], 'finish_reason' => 'stop']]]) . "\n\n";

            return;
        }

        if (($payload['model'] ?? null) === 'cut') {
            echo $delta('Hel');

            return;
        }

        foreach (['Hel', 'lo'] as $content) {
            echo 'data: ' . json_encode(['choices' => [['index' => 0, 'delta' => ['content' => $content]]]]) . "\n\n";
            flush();
            usleep(200_000);
        }

        echo 'data: ' . json_encode(['choices' => [['index' => 0, 'delta' => [], 'finish_reason' => 'stop']]]) . "\n\n";
        echo "data: [DONE]\n\n";

        return;
    }

    sleep(1);
    header('Content-Type: application/json');
    echo json_encode([
        'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => 'pong'], 'finish_reason' => 'stop']],
        'usage' => ['prompt_tokens' => 3, 'completion_tokens' => 1],
    ]);

    return;
}

if ($path === '/v1/lines') {
    foreach (["data: one\n", 'data: tw', "o\n", 'last'] as $i => $piece) {
        if ($i > 0) {
            usleep(300_000);
        }

        echo $piece;
        flush();
    }

    return;
}

if ($path === '/v1/until-abort' && $mark !== null) {
    ignore_user_abort(true);

    for ($i = 0; $i < 100; ++$i) {
        echo "data: {$i}\n";
        flush();

        if (connection_aborted() === 1) {
            file_put_contents($mark, "aborted after {$i} lines\n", FILE_APPEND);

            return;
        }

        usleep(50_000);
    }

    file_put_contents($mark, "completed\n", FILE_APPEND);

    return;
}

if ($path === '/v1/stall') {
    echo "data: first\n";
    flush();
    sleep(3);
    echo "data: late\n";

    return;
}

if (preg_match('#^/v1/bytes/(\d+)$#', $path, $matches) === 1) {
    for ($sent = 0, $total = (int) $matches[1]; $sent < $total; $sent += 1024) {
        echo str_repeat('x', min(1024, $total - $sent));
        flush();
    }

    return;
}

if (preg_match('#^/v1/status/(\d{3})$#', $path, $matches) === 1) {
    http_response_code((int) $matches[1]);
    echo '{"error":"upstream refused"}';

    return;
}

if ($path === '/v1/echo') {
    header('Content-Type: application/json');
    echo json_encode([
        'method' => $_SERVER['REQUEST_METHOD'],
        'contentType' => $_SERVER['CONTENT_TYPE'] ?? null,
        'authorization' => $_SERVER['HTTP_AUTHORIZATION'] ?? null,
        'custom' => $_SERVER['HTTP_X_CUSTOM'] ?? null,
        'body' => $body,
    ]);

    return;
}

if (preg_match('#^/v1/slow/(\d+)$#', $path, $matches) === 1) {
    usleep((int) $matches[1] * 1000);
    echo 'done';

    return;
}

if ($path === '/record') {
    echo 'recorded';

    return;
}

http_response_code(404);
