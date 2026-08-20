<?php

declare(strict_types=1);

/**
 * A minimal stand-in for the Lambda Runtime API, driven by a real
 * BrefLambdaAdapter over a real socket — the same "against a real
 * server, not a mock" discipline every other real-backend proof in this
 * project follows. State lives on disk under LAMBDA_TEST_STATE_DIR
 * (set via proc_open()'s own $env argument) since `php -S` spawns a
 * fresh process per request, so nothing survives in memory between
 * requests the way it would behind a persistent worker.
 *
 * .../invocation/next answers with the fixture event exactly once, then
 * starts failing with a 500 — that failure is what run()'s underlying
 * request() now surfaces as a thrown exception instead of silently
 * returning '', which is what stops this file's otherwise-infinite
 * poll loop after exactly one real invocation, for the test driving it.
 *
 * Two optional, disk-driven knobs let a test exercise the long-poll and
 * malformed-event handling without waiting minutes or faking the
 * Runtime API's own contract: a `delay-{next,response}.txt` file (a
 * plain float, seconds) sleeps before answering that one route; an
 * `event.json` whose content isn't a well-formed JSON object/array is
 * served verbatim, exactly as any other event body would be.
 */

$stateDir = getenv('LAMBDA_TEST_STATE_DIR');

if ($stateDir === false) {
    http_response_code(500);
    echo 'LAMBDA_TEST_STATE_DIR is not set';

    return;
}

function applyConfiguredDelay(string $stateDir, string $name): void
{
    $file = "{$stateDir}/delay-{$name}.txt";

    if (is_file($file)) {
        usleep((int) ((float) file_get_contents($file) * 1_000_000));
    }
}

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($path === '/2018-06-01/runtime/invocation/next' && $method === 'GET') {
    $counterFile = "{$stateDir}/counter.txt";
    $count = is_file($counterFile) ? (int) file_get_contents($counterFile) : 0;
    file_put_contents($counterFile, (string) ($count + 1));

    if ($count > 0) {
        http_response_code(500);
        echo 'no further events';

        return;
    }

    applyConfiguredDelay($stateDir, 'next');

    header('Lambda-Runtime-Aws-Request-Id: test-request-1');
    header('Content-Type: application/json');
    echo (string) file_get_contents("{$stateDir}/event.json");

    return;
}

if (preg_match('#^/2018-06-01/runtime/invocation/([^/]+)/response$#', $path, $matches) === 1 && $method === 'POST') {
    applyConfiguredDelay($stateDir, 'response');
    file_put_contents("{$stateDir}/response-{$matches[1]}.json", (string) file_get_contents('php://input'));
    http_response_code(202);

    return;
}

if (preg_match('#^/2018-06-01/runtime/invocation/([^/]+)/error$#', $path, $matches) === 1 && $method === 'POST') {
    file_put_contents("{$stateDir}/error-{$matches[1]}.json", (string) file_get_contents('php://input'));
    http_response_code(202);

    return;
}

http_response_code(404);
