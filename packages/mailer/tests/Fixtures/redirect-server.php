<?php

declare(strict_types=1);

/**
 * The router both halves of the redirect test run under `php -S`.
 *
 * `/send` answers 302 pointing at `REDIRECT_TO`. `/sink` records the
 * complete request it received, so "nothing reached the target" is the
 * absence of a file rather than a claim.
 */

$path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$log = (string) getenv('SINK_LOG');

if ($path === '/sink') {
    file_put_contents($log, json_encode([
        'method' => $_SERVER['REQUEST_METHOD'] ?? '',
        'authorization' => $_SERVER['HTTP_AUTHORIZATION'] ?? '',
        'body' => file_get_contents('php://input'),
    ], JSON_THROW_ON_ERROR) . "\n", FILE_APPEND);

    echo 'sunk';

    return true;
}

header('Location: ' . (string) getenv('REDIRECT_TO'), true, 302);
echo 'moved';

return true;
