<?php

declare(strict_types=1);

// The front controller SuperglobalsDriver serves through `php -S`: the
// real SuperglobalsBridge::handle() with a handler that records what it
// received and answers with whatever the driver asked for.
//
// The response must leave the wire untouched — its status, headers,
// cookies and bytes are what the suite asserts on — so the observed
// request cannot ride in the response. It goes to a file instead, named
// by the per-dispatch id the driver sends, under the directory the
// driver passed in through the environment (the same shape as
// kinetis/bref-adapter's fake Runtime API fixture).
//
// A parse failure inside SuperglobalsBridge::handle() never reaches this
// handler at all — that is the point of the conformance case that
// exercises it — so a missing observed-request file is a meaningful
// outcome the driver reports as "the handler never ran", not an error.

require __DIR__ . '/../../../../vendor/autoload.php';

use Kinetis\Runtime\SuperglobalsBridge;
use Kinetis\Testing\Runtime\ObservedRequest;
use Kinetis\Testing\Runtime\ResponseSpec;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

SuperglobalsBridge::handle(static function (ServerRequestInterface $request): ResponseInterface {
    $stateDir = getenv('KINETIS_CONFORMANCE_STATE_DIR');
    $id = $request->getHeaderLine('X-Conformance-Id');

    if (!is_string($stateDir) || $stateDir === '' || preg_match('/^[0-9a-f]{32}$/', $id) !== 1) {
        throw new LogicException('conformance front controller invoked without its state directory or dispatch id');
    }

    file_put_contents(
        $stateDir . '/' . $id . '.json',
        json_encode(ObservedRequest::fromServerRequest($request)->toArray(), JSON_THROW_ON_ERROR),
    );

    /** @var array<string, mixed> $spec */
    $spec = json_decode(
        (string) base64_decode($request->getHeaderLine('X-Conformance-Response'), strict: true),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    return ResponseSpec::fromArray($spec)->toResponse();
});
