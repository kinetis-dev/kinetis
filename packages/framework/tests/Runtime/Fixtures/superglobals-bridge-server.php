<?php

declare(strict_types=1);

// Real front controller for SuperglobalsBridgeHandleTest — exercises the
// actual adapter call shape (SuperglobalsBridge::handle($handler)), not a
// simulation, since request_parse_body()'s failure modes only reproduce
// against a real SAPI request context (confirmed directly: a bare CLI
// script with $_SERVER['CONTENT_TYPE'] set by hand never reproduces this
// at all — it needs a real request php -S actually received).

require __DIR__ . '/../../../vendor/autoload.php';

use Kinetis\Runtime\SuperglobalsBridge;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

SuperglobalsBridge::handle(static function (ServerRequestInterface $request): ResponseInterface {
    return new Response(200, ['Content-Type' => 'application/json'], (string) json_encode(['ok' => true]));
});
